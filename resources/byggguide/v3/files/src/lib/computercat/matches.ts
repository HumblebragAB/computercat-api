import type { Authed, AuthedMeta } from './auth';
import { ApiError, isRecord, type HttpMethod, type MetaResult } from './http';

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

function unexpected(method: HttpMethod, path: string): Error {
  return new Error(`Oväntat svar från ${method} ${path}`);
}

export type MatchesDeps = { authed: Authed; authedMeta: AuthedMeta };

export function createMatches(game: string, deps: MatchesDeps) {
  const base = `/games/${seg(game)}/matches`;

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
    return snap;
  }

  /** Anrop vars svar är {match, join_token} (create, invite). */
  async function inviteCall(method: HttpMethod, path: string, body?: unknown): Promise<{ match: MatchSnapshot; joinToken: string }> {
    const res = await call(method, path, body);
    const b = res.body;
    const match = isRecord(b) ? parseSnapshot(b.match, res.etag) : null;
    if (!match || !isRecord(b) || typeof b.join_token !== 'string' || b.join_token === '') throw unexpected(method, path);
    return { match, joinToken: b.join_token };
  }

  function get(id: string, since?: number): Promise<MatchSnapshot> {
    if (since !== undefined && (!Number.isSafeInteger(since) || since < 0)) {
      throw new RangeError(`since måste vara ett heltal ≥ 0, fick ${since}`);
    }
    const query = since === undefined ? '' : `?since=${since}`;
    return snapshotCall('GET', `${base}/${seg(id)}${query}`);
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
