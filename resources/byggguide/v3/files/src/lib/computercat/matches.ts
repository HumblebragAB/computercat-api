import type { Authed, AuthedMeta } from './auth';
import { ApiError, NetworkError, isRecord, type HttpMethod, type MetaResult } from './http';

/**
 * Turbaserade tvåspelarmatcher (MatchController). Servern äger reglerna och det dolda
 * läget; klienten skickar drag med snapshotens `version` och pollar efter ändringar.
 */

export type MatchStatus = 'waiting' | 'active' | 'finished' | 'abandoned';
export type Seat = 0 | 1;
export type MatchPlayer = { seat: Seat; name: string; connected: boolean };
export type MatchEvent = { seq: number; type: string; seat: Seat | null; payload: Record<string, unknown>; at: string | null };
export type MatchSnapshot = {
  id: string;
  ruleset: string;
  status: MatchStatus;
  version: number;
  yourSeat: Seat;
  players: MatchPlayer[];
  state: Record<string, unknown>;
  you: Record<string, unknown>;
  events: MatchEvent[];
  /** ETag från svaret (`"{version}-{c0}{c1}"`). Null för create, som inte har någon. */
  etag: string | null;
};
export type ActiveMatch = { id: string; ruleset: string; status: MatchStatus; yourSeat: Seat };

/**
 * Ett kodat fel från matchvägarna: `code` som servern skickar (stale, invite_expired, not_your_turn,
 * rate_limited, too_many_active, …), `not_found` för 404 och `validation` för 422 med `errors`.
 * Vid `stale` från act ligger den omhämtade matchen i `snapshot` (om omhämtningen lyckades).
 */
export class MatchError extends Error {
  readonly code: string;
  readonly status: number;
  readonly body: unknown;
  snapshot?: MatchSnapshot;

  constructor(code: string, status: number, message: string, body: unknown) {
    super(message);
    this.name = 'MatchError';
    this.code = code;
    this.status = status;
    this.body = body;
  }
}

const seg = encodeURIComponent;
const STATUSES: readonly string[] = ['waiting', 'active', 'finished', 'abandoned'];

/** Spegel av MatchService::NAME_PATTERN (`/\A[^\p{C}]{1,20}\z/u`): kodpunkter, inga kontroll- eller formattecken. */
const NAME_PATTERN = /^[^\p{C}]{1,20}$/u;

/**
 * Kastar RangeError synkront för ett namn som servern skulle avvisa med 422.
 * Trimmar först, som servern (TrimStrings). Returnerar det trimmade namnet.
 */
export function assertName(name: string): string {
  const trimmed = name.trim();
  if (!NAME_PATTERN.test(trimmed)) {
    throw new RangeError('Namnet ska vara 1–20 tecken utan kontrolltecken.');
  }
  return trimmed;
}

/**
 * Namnet som ska skickas, eller undefined. Tomt efter trim skickas inte: servern gör ''
 * till null (ConvertEmptyStringsToNull) och ger då standardnamnet (Spelare 1/2).
 */
function nameField(name: string | undefined): { name?: string } {
  if (name === undefined || name.trim() === '') return {};
  return { name: assertName(name) };
}

function isSeat(v: unknown): v is Seat {
  return v === 0 || v === 1;
}

/** PHP:s tomma array blir JSON []: tolkas som {}. Andra icke-objekt ger null (fel form). */
function asObject(v: unknown): Record<string, unknown> | null {
  if (Array.isArray(v)) return v.length === 0 ? {} : null;
  return isRecord(v) ? v : null;
}

function parsePlayer(v: unknown): MatchPlayer | null {
  if (!isRecord(v) || !isSeat(v.seat) || typeof v.name !== 'string' || typeof v.connected !== 'boolean') return null;
  return { seat: v.seat, name: v.name, connected: v.connected };
}

function parseEvent(v: unknown): MatchEvent | null {
  if (!isRecord(v) || !Number.isSafeInteger(v.seq) || typeof v.type !== 'string') return null;
  if (!(v.seat === null || isSeat(v.seat))) return null;
  if (!(v.at === null || typeof v.at === 'string')) return null;
  const payload = asObject(v.payload);
  if (payload === null) return null;
  return { seq: v.seq as number, type: v.type, seat: v.seat, payload, at: v.at };
}

/** Formkontroll av MatchView::for. Null om något fält saknas eller har fel typ. */
export function parseSnapshot(v: unknown, etag: string | null): MatchSnapshot | null {
  if (!isRecord(v)) return null;
  if (typeof v.id !== 'string' || typeof v.ruleset !== 'string') return null;
  if (typeof v.status !== 'string' || !STATUSES.includes(v.status)) return null;
  if (!Number.isSafeInteger(v.version) || (v.version as number) < 0) return null;
  if (!isSeat(v.your_seat)) return null;
  if (!Array.isArray(v.players) || !Array.isArray(v.events)) return null;
  const state = asObject(v.state);
  const you = asObject(v.you);
  if (state === null || you === null) return null;
  const players = v.players.map(parsePlayer);
  const events = v.events.map(parseEvent);
  if (players.some((p) => p === null) || events.some((e) => e === null)) return null;
  return {
    id: v.id,
    ruleset: v.ruleset,
    status: v.status as MatchStatus,
    version: v.version as number,
    yourSeat: v.your_seat,
    players: players as MatchPlayer[],
    state,
    you,
    events: events as MatchEvent[],
    etag,
  };
}

/** 4xx med `code` blir MatchError; 404 not_found; 422 med `errors` validation. Övrigt oförändrat. */
export function toMatchError(e: unknown): unknown {
  if (!(e instanceof ApiError) || e.status < 400 || e.status >= 500) return e;
  const body = e.body;
  let code: string | null = null;
  if (isRecord(body) && typeof body.code === 'string') code = body.code;
  else if (e.status === 404) code = 'not_found';
  else if (e.status === 422 && isRecord(body) && isRecord(body.errors)) code = 'validation';
  return code === null ? e : new MatchError(code, e.status, e.message, body);
}

class ShapeError extends Error {}

function unexpected(method: HttpMethod, path: string): Error {
  return new ShapeError(`Oväntat svar från ${method} ${path}`);
}

/** Pollintervall när matchen pågår eller väntar. */
export const POLL_INTERVAL_MS = 1500;
/** Väntan efter nätfel och 5xx (och 429 utan Retry-After). */
export const POLL_BACKOFF_MS = 5000;
/** Pollintervall för en avslutad match (revansch kan komma) så att gränsen 120/min räcker. */
export const FINISHED_INTERVAL_MS = 5000;
/** onError för samma felserie (nät, 5xx, fel form) högst så här ofta. */
export const ERROR_REPORT_MS = 10_000;

/**
 * Orsaken som går till watch.onError. `gone` (404/410 eller abandoned) är alltid terminal.
 * `auth` finns inte: authedMeta byter spelare vid 401, och den nya spelaren får 404 = `gone`.
 */
export type WatchError = { kind: 'network' | 'server' | 'rate_limited' | 'gone' | 'shape'; error: unknown; terminal: boolean };
export type WatchHandlers = { onChange(s: MatchSnapshot): void; onError(e: WatchError): void };
export type WatchOptions = {
  intervalMs?: number;
  backoffMs?: number;
  doc?: Pick<Document, 'visibilityState' | 'addEventListener' | 'removeEventListener'>;
  /** pageshow och online lyssnas på här. Standard: window (läses vid anrop). */
  win?: Pick<Window, 'addEventListener' | 'removeEventListener'>;
};

/** Händelser ihop, unika på seq (den senaste vinner), sorterade. */
function mergeEvents(a: MatchEvent[], b: MatchEvent[]): MatchEvent[] {
  const bySeq = new Map<number, MatchEvent>();
  for (const e of a) bySeq.set(e.seq, e);
  for (const e of b) bySeq.set(e.seq, e);
  return [...bySeq.values()].sort((x, y) => x.seq - y.seq);
}

/** Retry-After begränsas till 1–60 s, så att ett orimligt värde inte fryser bevakningen. */
export const RETRY_AFTER_MAX_S = 60;

/** Djup kopia av ren JSON (ögonblicksbilderna kommer från parseSnapshot). */
function jsonCopy<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

/**
 * Djupfryst kopia till spelet: det kan inte ändra bevakningens interna sammanslagning.
 * Ögonblicksbilden är ren JSON (parseSnapshot), så en JSON-kopia räcker. Kastar kopieringen
 * fångas det i poll och går till onError som `shape`.
 */
function frozenCopy<T>(value: T): T {
  const copy = jsonCopy(value);
  const freeze = (v: unknown): void => {
    if (typeof v !== 'object' || v === null || Object.isFrozen(v)) return;
    Object.freeze(v);
    for (const child of Object.values(v)) freeze(child);
  };
  freeze(copy);
  return copy;
}

function maxSeq(events: MatchEvent[]): number | null {
  return events.length === 0 ? null : events[events.length - 1].seq;
}

export type MatchesDeps = { authed: Authed; authedMeta: AuthedMeta };

export function createMatches(game: string, deps: MatchesDeps) {
  const base = `/games/${seg(game)}/matches`;
  /**
   * Senast kända ögonblicksbild per match, med alla händelser vi sett. Fylls av varje svar
   * (poll, act, join, create, invite, leave, rematch), så att watch skickar rätt If-None-Match
   * och since och kastar svar med lägre version än vi redan sett.
   */
  const known = new Map<string, MatchSnapshot>();

  /**
   * Spara svaret. Lägre version än känd ignoreras och ger false. En egen kopia sparas: spelet får
   * samma objekt som svar från act, join, get, create och invite och kan ändra dem utan att `known` påverkas.
   */
  function remember(s: MatchSnapshot): boolean {
    const prev = known.get(s.id);
    if (prev && s.version < prev.version) return false;
    known.set(s.id, jsonCopy({ ...s, events: prev ? mergeEvents(prev.events, s.events) : mergeEvents([], s.events) }));
    return true;
  }

  async function call(method: HttpMethod, path: string, body?: unknown, noStore = false): Promise<MetaResult<unknown>> {
    try {
      return await deps.authedMeta<unknown>(method, path, body === undefined ? { noStore } : { body, noStore });
    } catch (e) {
      throw toMatchError(e);
    }
  }

  /** Anrop vars svar är en ögonblicksbild (join, get, act, leave, rematch). */
  async function snapshotCall(method: HttpMethod, path: string, body?: unknown): Promise<MatchSnapshot> {
    const res = await call(method, path, body, method === 'GET');
    const snap = parseSnapshot(res.body, res.etag);
    if (!snap) throw unexpected(method, path);
    remember(snap);
    return snap;
  }

  /** Anrop vars svar är {match, join_token} (create, invite). */
  async function inviteCall(method: HttpMethod, path: string, body?: unknown): Promise<{ match: MatchSnapshot; joinToken: string }> {
    const res = await call(method, path, body);
    const b = res.body;
    const match = isRecord(b) ? parseSnapshot(b.match, res.etag) : null;
    if (!match || !isRecord(b) || typeof b.join_token !== 'string' || b.join_token === '') throw unexpected(method, path);
    remember(match);
    return { match, joinToken: b.join_token };
  }

  function get(id: string, since?: number): Promise<MatchSnapshot> {
    if (since !== undefined && (!Number.isSafeInteger(since) || since < 0)) {
      throw new RangeError(`since måste vara ett heltal ≥ 0, fick ${since}`);
    }
    const query = since === undefined ? '' : `?since=${since}`;
    return snapshotCall('GET', `${base}/${seg(id)}${query}`);
  }

  /**
   * Bevaka en match: första pollen direkt, sedan var 1,5 s (5 s när den är finished), paus när
   * fliken är dold. onChange får hela den kända ögonblicksbilden med alla händelser hittills.
   * Stannar aldrig tyst: varje fel går till onError, och terminala fel avslutar bevakningen.
   * Vid händelsen `rematch` kommer ett sista onChange; byt sedan till watch(payload.match_id).
   */
  function watch(id: string, handlers: WatchHandlers, opts: WatchOptions = {}): { stop(): void; pollNow(): void } {
    const intervalMs = opts.intervalMs ?? POLL_INTERVAL_MS;
    const backoffMs = opts.backoffMs ?? POLL_BACKOFF_MS;
    // Webbläsarglobalerna läses här, vid anrop, aldrig vid import (statisk export, testmiljön node).
    const doc = opts.doc ?? (typeof document !== 'undefined' ? document : undefined);
    const win = opts.win ?? (typeof window !== 'undefined' ? window : undefined);
    const path = `${base}/${seg(id)}`;

    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let inFlight: AbortController | null = null;
    /** Sant efter ett icke-terminalt fel tills en poll lyckas (då kommer onChange även vid 304). */
    let failing = false;
    let lastReport = -Infinity;
    /** Backoff eller Retry-After: pollNow väntar till dess (synlig flik, pageshow och online gör det inte). */
    let notBefore = 0;
    /** Senast skickade (version, etag). onChange får varje ny, oavsett vilket anrop som hämtade den. */
    let lastEmitted: { version: number; etag: string | null } | null = null;

    const hidden = () => doc?.visibilityState === 'hidden';

    function safely(name: 'onChange' | 'onError', fn: () => void): void {
      try {
        fn();
      } catch (e) {
        console.error(`[computercat] watch.${name} kastade`, e);
      }
    }
    /** Kastar om kopian inte går att bygga; lastEmitted sätts först efteråt, så nästa poll försöker igen. */
    function change(s: MatchSnapshot): void {
      if (stopped) return;
      const frozen = frozenCopy(s);
      lastEmitted = { version: s.version, etag: s.etag };
      safely('onChange', () => handlers.onChange(frozen));
    }
    function fail(e: WatchError): void {
      if (!stopped) safely('onError', () => handlers.onError(e));
    }

    function clearTimer(): void {
      if (timer !== null) clearTimeout(timer);
      timer = null;
    }
    function schedule(ms: number): void {
      clearTimer();
      if (stopped || hidden()) return;
      timer = setTimeout(() => {
        timer = null;
        void poll();
      }, ms);
    }

    function stop(): void {
      if (stopped) return;
      stopped = true;
      clearTimer();
      inFlight?.abort();
      inFlight = null;
      doc?.removeEventListener('visibilitychange', onVisibility);
      win?.removeEventListener('pageshow', pollAnyway);
      win?.removeEventListener('online', pollAnyway);
    }

    /** Status och händelser i den kända ögonblicksbilden avgör nästa steg. */
    function settle(current: MatchSnapshot | undefined): void {
      if (current && current.events.some((e) => e.type === 'rematch')) {
        stop();
        return;
      }
      if (current?.status === 'abandoned') {
        fail({ kind: 'gone', error: new Error('Matchen avbröts (abandoned).'), terminal: true });
        stop();
        return;
      }
      schedule(current?.status === 'finished' ? FINISHED_INTERVAL_MS : intervalMs);
    }

    /** Nät, 5xx och fel form: rapportera första felet i serien, sedan högst var 10:e sekund. */
    function seriesError(kind: 'network' | 'server' | 'shape', error: unknown): void {
      const now = Date.now();
      if (!failing || now - lastReport >= ERROR_REPORT_MS) {
        lastReport = now;
        fail({ kind, error, terminal: false });
      }
      failing = true;
      backoff(backoffMs);
    }

    function backoff(ms: number): void {
      notBefore = Date.now() + ms;
      schedule(ms);
    }

    function handleError(raw: unknown): void {
      const e = toMatchError(raw);
      const status = e instanceof MatchError || e instanceof ApiError ? e.status : null;
      if (status === 404 || status === 410) {
        fail({ kind: 'gone', error: e, terminal: true });
        stop();
      } else if (status === 429 && !(e instanceof MatchError && e.code === 'too_many_active')) {
        // Ett Retry-After som inte är ett ändligt tal (NaN, Infinity) räknas som saknat.
        const retryAfter = raw instanceof ApiError && Number.isFinite(raw.retryAfter) ? (raw.retryAfter as number) : null;
        fail({ kind: 'rate_limited', error: e, terminal: false });
        failing = true;
        backoff(retryAfter === null ? backoffMs : Math.min(Math.max(retryAfter, 1), RETRY_AFTER_MAX_S) * 1000);
      } else if (e instanceof NetworkError) {
        seriesError('network', e);
      } else if (e instanceof ShapeError) {
        seriesError('shape', e);
      } else if (status !== null && status >= 500) {
        seriesError('server', e);
      } else {
        // Övriga 4xx (t.ex. 401 två gånger, 422): samma anrop lyckas inte igen. Avsluta högt.
        fail({ kind: 'server', error: e, terminal: true });
        stop();
      }
    }

    async function poll(): Promise<void> {
      if (stopped || inFlight || hidden()) return;
      clearTimer();
      const ctrl = new AbortController();
      inFlight = ctrl;
      const before = known.get(id);
      const since = before ? maxSeq(before.events) : null;
      const url = since === null ? path : `${path}?since=${since}`;
      const headers: Record<string, string> = before?.etag ? { 'If-None-Match': before.etag } : {};
      let res: MetaResult<unknown> | null = null;
      let error: unknown = null;
      try {
        res = await deps.authedMeta<unknown>('GET', url, { headers, noStore: true, signal: ctrl.signal });
      } catch (e) {
        error = e;
      }
      if (inFlight === ctrl) inFlight = null;
      // Avbruten av oss (dold flik eller stop): inget fel att rapportera, och svaret gäller inte.
      if (ctrl.signal.aborted || stopped) return;
      // poll körs som `void poll()`: ett fel här får aldrig bli ett ohanterat avvisat löfte som
      // tyst stoppar bevakningen. Det går till onError och nästa poll schemaläggs (backoff).
      try {
        if (error !== null || res === null) handleError(error);
        else afterResponse(res, url);
      } catch (e) {
        if (!stopped) seriesError('shape', e);
      }
    }

    /** Tolka ett lyckat svar (200 eller 304), skicka onChange vid behov och schemalägg nästa poll. */
    function afterResponse(res: MetaResult<unknown>, url: string): void {
      if (res.status !== 304) {
        const snap = parseSnapshot(res.body, res.etag);
        if (!snap || snap.id !== id) {
          handleError(unexpected('GET', url));
          return;
        }
        // remember kastar ett svar med lägre version än känd (från en tidigare poll eller act).
        remember(snap);
      }
      // Den kända ögonblicksbilden kan också komma från act, join, invite eller en omhämtning
      // efter stale: deras ETag ger 304 här, men spelet ska ändå få den. Efter ett fel kommer
      // onChange även utan ändring, så att spelet kan släcka sin banner.
      const current = known.get(id);
      if (current && (failing || lastEmitted === null || lastEmitted.version !== current.version || lastEmitted.etag !== current.etag)) {
        change(current);
      }
      failing = false;
      notBefore = 0;
      settle(current);
    }

    /** Spelets pollNow: väntar ut en pågående backoff eller Retry-After. */
    function pollNow(): void {
      if (Date.now() < notBefore) return;
      void poll();
    }

    /** Synlig flik, pageshow och online: poll direkt, också under backoff. */
    function pollAnyway(): void {
      void poll();
    }

    function onVisibility(): void {
      if (stopped) return;
      if (hidden()) {
        // Pausa och avbryt en hängande fetch (iOS Safari släpper den annars inte).
        clearTimer();
        inFlight?.abort();
        inFlight = null;
      } else {
        pollAnyway();
      }
    }

    doc?.addEventListener('visibilitychange', onVisibility);
    win?.addEventListener('pageshow', pollAnyway);
    win?.addEventListener('online', pollAnyway);
    pollAnyway();

    return { stop, pollNow };
  }

  return {
    /** Ny match, du får säte 0. joinToken visas en gång (som länk/QR); servern sparar bara en hash. */
    create(ruleset: string, options?: Record<string, unknown>, name?: string): Promise<{ match: MatchSnapshot; joinToken: string }> {
      const body = { ruleset, options: options ?? {}, ...nameField(name) };
      return inviteCall('POST', base, body);
    },

    /** Gå med som säte 1 och starta matchen. */
    join(token: string, name?: string): Promise<MatchSnapshot> {
      const body = { token, ...nameField(name) };
      return snapshotCall('POST', `${base}/join`, body);
    },

    get,
    watch,

    /**
     * Ett drag på den snapshot spelaren såg (`version` skickas med). Vid `stale` hämtas matchen
     * igen och MatchError('stale') kastas med den nya ögonblicksbilden i `snapshot`.
     */
    async act(snapshot: MatchSnapshot, type: string, payload?: Record<string, unknown>): Promise<MatchSnapshot> {
      const path = `${base}/${seg(snapshot.id)}/actions`;
      try {
        return await snapshotCall('POST', path, { type, payload: payload ?? {}, version: snapshot.version });
      } catch (e) {
        if (e instanceof MatchError && e.code === 'stale') {
          try {
            e.snapshot = await get(snapshot.id);
          } catch (refetch) {
            console.warn('[computercat] matchen kunde inte hämtas igen efter stale', refetch);
          }
        }
        throw e;
      }
    },

    /** Lämna matchen (blir abandoned). På en avslutad match: oförändrad ögonblicksbild. */
    leave(id: string): Promise<MatchSnapshot> {
      return snapshotCall('POST', `${base}/${seg(id)}/leave`);
    },

    /** Begär revansch (bara finished). När båda begärt kommer händelsen `rematch` med `{match_id}`. */
    rematch(id: string): Promise<MatchSnapshot> {
      return snapshotCall('POST', `${base}/${seg(id)}/rematch`);
    },

    /** Ny inbjudningstoken för din väntande match (säte 0). Den gamla slutar gälla. */
    invite(id: string): Promise<{ match: MatchSnapshot; joinToken: string }> {
      return inviteCall('POST', `${base}/${seg(id)}/invite`);
    },

    /** Dina öppna matcher (waiting/active) i spelet, för att återansluta efter en omladdning. */
    async active(): Promise<ActiveMatch[]> {
      const path = `${base}/active`;
      let res: unknown;
      try {
        res = await deps.authed<unknown>('GET', path);
      } catch (e) {
        throw toMatchError(e);
      }
      if (!isRecord(res) || !Array.isArray(res.data)) throw unexpected('GET', path);
      return res.data.map((m: unknown) => {
        if (!isRecord(m) || typeof m.id !== 'string' || typeof m.ruleset !== 'string' || typeof m.status !== 'string' || !STATUSES.includes(m.status) || !isSeat(m.your_seat)) {
          throw unexpected('GET', path);
        }
        return { id: m.id, ruleset: m.ruleset, status: m.status as MatchStatus, yourSeat: m.your_seat };
      });
    },
  };
}

export type Matches = ReturnType<typeof createMatches>;

/** https-origin (valfri sökväg) eller http://localhost[:port] / http://127.0.0.1[:port] för utveckling. */
const PUBLIC_ORIGIN = /^(https:\/\/[^/?#\s]+|http:\/\/(localhost|127\.0\.0\.1)(:\d+)?)(\/[^?#\s]*)?$/;

/**
 * Inbjudningslänken `${origin}/#join=<token>`. Fragment, inte query: token hamnar varken i
 * serverloggar eller i Referer. Utan origin används window.location.origin, men bara när den är
 * https (eller http://localhost, http://127.0.0.1); i iOS-appen är den capacitor://localhost och måste ersättas av
 * spelets publika adress.
 */
export function inviteUrl(token: string, origin?: string): string {
  const o = origin ?? (typeof window !== 'undefined' ? window.location.origin : '');
  if (!PUBLIC_ORIGIN.test(o)) {
    throw new Error(`inviteUrl: skicka in spelets publika webbadress (https://…) som origin; "${o}" fungerar inte som länk för en vän.`);
  }
  return `${o.replace(/\/+$/, '')}/#join=${encodeURIComponent(token)}`;
}

/** Inbjudningstoken: 32 byte som base64url utan utfyllnad (MatchService::token). */
const JOIN_TOKEN = /^[A-Za-z0-9_-]{43}$/;

/** Delar `a=1&join=x` i join-värdet och resten (ordagrant, inget omkodas). */
function takeJoin(part: string): { value: string | null; found: boolean; rest: string } {
  let value: string | null = null;
  let found = false;
  const rest = part
    .split('&')
    .filter((kv) => {
      if (kv.split('=', 1)[0] !== 'join') return true;
      if (!found) {
        found = true;
        const raw = kv.slice('join='.length);
        try {
          value = decodeURIComponent(raw);
        } catch {
          value = null;
        }
      }
      return false;
    })
    .join('&');
  return { value, found, rest };
}

/**
 * Läser inbjudningstoken ur `#join=` (och, bakåtkompatibelt, `?join=`). Bara en giltig token
 * (43 tecken base64url) returneras; annars null. `join` tas alltid bort ur adressen med
 * history.replaceState, också när värdet var ogiltigt, så att token inte ligger kvar i historiken.
 */
export function readJoinToken(
  loc?: Pick<Location, 'pathname' | 'search' | 'hash'>,
  hist?: Pick<History, 'state' | 'replaceState'>,
): string | null {
  const l = loc ?? (typeof location !== 'undefined' ? location : undefined);
  const h = hist ?? (typeof history !== 'undefined' ? history : undefined);
  if (!l) return null;
  const hash = takeJoin(l.hash.replace(/^#/, ''));
  const search = takeJoin(l.search.replace(/^\?/, ''));
  if (!hash.found && !search.found) return null;
  const url = `${l.pathname}${search.rest ? `?${search.rest}` : ''}${hash.rest ? `#${hash.rest}` : ''}`;
  h?.replaceState(h.state, '', url);
  const value = hash.found ? hash.value : search.value;
  return value !== null && JOIN_TOKEN.test(value) ? value : null;
}
