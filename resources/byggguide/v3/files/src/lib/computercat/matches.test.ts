import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError } from './http';
import { MatchError, assertName, createMatches, inviteUrl, readJoinToken, type MatchSnapshot } from './matches';

const GAME = 'duell';
const ID = '01JB8Z3Q4WXYZ0123456789ABC';
const M = `/games/${GAME}/matches`;
/** Som MatchService::token(): 32 slumpbytes som base64url utan utfyllnad = 43 tecken. */
const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcde';

/** Svarsformen från MatchView::for. */
function view(over: Record<string, unknown> = {}) {
  return {
    id: ID,
    ruleset: 'reveal',
    status: 'active',
    version: 7,
    your_seat: 0,
    players: [
      { seat: 0, name: 'Anna', connected: true },
      { seat: 1, name: 'Bo', connected: false },
    ],
    state: { turn: 0 },
    you: { secret: 42 },
    events: [{ seq: 12, type: 'left', seat: 1, payload: {}, at: '2026-10-17T10:00:00+00:00' }],
    ...over,
  };
}

/** Som servern: respond() sätter ETag med citattecken (setEtag). */
function withEtag(status: number, body: unknown, etag: string): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: etag },
  });
}

function setup(routes: Parameters<typeof fakeFetch>[0], game = GAME) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game, storage: storedSession(game) });
  return { f, m: createMatches(game, { authed: auth.authed, authedMeta: auth.authedMeta }) };
}

function snapshotOf(over: Partial<MatchSnapshot> = {}): MatchSnapshot {
  return {
    id: ID,
    ruleset: 'reveal',
    status: 'active',
    version: 7,
    yourSeat: 0,
    players: [],
    state: {},
    you: {},
    events: [],
    etag: null,
    ...over,
  };
}

beforeEach(() => {
  vi.spyOn(console, 'error').mockImplementation(() => {});
  vi.spyOn(console, 'warn').mockImplementation(() => {});
});
afterEach(() => {
  vi.restoreAllMocks();
});

describe('createMatches: anropen', () => {
  it('create skickar ruleset, options och namn och ger joinToken; etag är null (create har ingen ETag)', async () => {
    const { f, m } = setup({ [`POST ${M}`]: [json(201, { match: view({ status: 'waiting', version: 0 }), join_token: TOKEN })] });
    const res = await m.create('reveal', { rounds: 3 }, 'Anna');
    expect(f.calls[0].method).toBe('POST');
    expect(f.calls[0].path).toBe(M);
    expect(f.calls[0].body).toEqual({ ruleset: 'reveal', options: { rounds: 3 }, name: 'Anna' });
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|stored');
    expect(res.joinToken).toBe(TOKEN);
    expect(res.match.status).toBe('waiting');
    expect(res.match.yourSeat).toBe(0);
    expect(res.match.etag).toBeNull();
    expect(f.unmatched).toEqual([]);
  });

  it('create utan options skickar {} och utan namn inget namn', async () => {
    const { f, m } = setup({ [`POST ${M}`]: [json(201, { match: view(), join_token: TOKEN })] });
    await m.create('reveal');
    expect(f.calls[0].body).toEqual({ ruleset: 'reveal', options: {} });
    expect(f.unmatched).toEqual([]);
  });

  it('join skickar token och namn och läser ETag', async () => {
    const { f, m } = setup({ [`POST ${M}/join`]: [withEtag(200, view({ your_seat: 1 }), '"7-11"')] });
    const s = await m.join(TOKEN, 'Bo');
    expect(f.calls[0].body).toEqual({ token: TOKEN, name: 'Bo' });
    expect(s.yourSeat).toBe(1);
    expect(s.etag).toBe('"7-11"');
    expect(f.unmatched).toEqual([]);
  });

  it('get läser ögonblicksbilden, med och utan since', async () => {
    const { f, m } = setup({
      [`GET ${M}/${ID}`]: [withEtag(200, view(), '"7-10"')],
      [`GET ${M}/${ID}?since=5`]: [withEtag(200, view({ events: [] }), '"7-10"')],
    });
    const s = await m.get(ID);
    expect(s).toEqual({
      id: ID,
      ruleset: 'reveal',
      status: 'active',
      version: 7,
      yourSeat: 0,
      players: [
        { seat: 0, name: 'Anna', connected: true },
        { seat: 1, name: 'Bo', connected: false },
      ],
      state: { turn: 0 },
      you: { secret: 42 },
      events: [{ seq: 12, type: 'left', seat: 1, payload: {}, at: '2026-10-17T10:00:00+00:00' }],
      etag: '"7-10"',
    });
    expect((await m.get(ID, 5)).events).toEqual([]);
    expect(f.calls.map((c) => c.method)).toEqual(['GET', 'GET']);
    expect(f.unmatched).toEqual([]);
  });

  it('sökvägssegment kodas', async () => {
    const { f, m } = setup({ 'GET /games/a%2Fb/matches/x%3Fy': [withEtag(200, view(), '"7-10"')] }, 'a/b');
    await m.get('x?y');
    expect(f.unmatched).toEqual([]);
  });

  it('act skickar type, payload och snapshotens version', async () => {
    const { f, m } = setup({ [`POST ${M}/${ID}/actions`]: [withEtag(200, view({ version: 8 }), '"8-11"')] });
    const s = await m.act(snapshotOf({ version: 7 }), 'guess', { value: 37 });
    expect(f.calls[0].body).toEqual({ type: 'guess', payload: { value: 37 }, version: 7 });
    expect(s.version).toBe(8);
    expect(s.etag).toBe('"8-11"');
    expect(f.unmatched).toEqual([]);
  });

  it('act utan payload skickar {}', async () => {
    const { f, m } = setup({ [`POST ${M}/${ID}/actions`]: [withEtag(200, view({ version: 8 }), '"8-11"')] });
    await m.act(snapshotOf(), 'ready');
    expect(f.calls[0].body).toEqual({ type: 'ready', payload: {}, version: 7 });
  });

  it('leave, rematch och invite postar till rätt väg', async () => {
    const { f, m } = setup({
      [`POST ${M}/${ID}/leave`]: [withEtag(200, view({ status: 'abandoned', version: 8 }), '"8-10"')],
      [`POST ${M}/${ID}/rematch`]: [withEtag(200, view({ status: 'finished', version: 9 }), '"9-10"')],
      [`POST ${M}/${ID}/invite`]: [withEtag(200, { match: view({ status: 'waiting', version: 1 }), join_token: TOKEN }, '"1-10"')],
    });
    expect((await m.leave(ID)).status).toBe('abandoned');
    expect((await m.rematch(ID)).version).toBe(9);
    const inv = await m.invite(ID);
    expect(inv.joinToken).toBe(TOKEN);
    expect(inv.match.etag).toBe('"1-10"');
    expect(f.calls.map((c) => `${c.method} ${c.path}`)).toEqual([
      `POST ${M}/${ID}/leave`,
      `POST ${M}/${ID}/rematch`,
      `POST ${M}/${ID}/invite`,
    ]);
    expect(f.unmatched).toEqual([]);
  });

  it('active ger id, ruleset, status och yourSeat', async () => {
    const { f, m } = setup({
      [`GET ${M}/active`]: [json(200, { data: [{ id: ID, ruleset: 'reveal', status: 'waiting', your_seat: 0 }] })],
    });
    expect(await m.active()).toEqual([{ id: ID, ruleset: 'reveal', status: 'waiting', yourSeat: 0 }]);
    expect(f.unmatched).toEqual([]);
  });
});

describe('createMatches: fel', () => {
  it('stale hämtar matchen igen och kastar MatchError med den nya ögonblicksbilden', async () => {
    const { f, m } = setup({
      [`POST ${M}/${ID}/actions`]: [json(409, { message: 'Matchen har ändrats.', code: 'stale', version: 9 })],
      [`GET ${M}/${ID}`]: [withEtag(200, view({ version: 9 }), '"9-11"')],
    });
    const err = await m.act(snapshotOf({ version: 7 }), 'guess', { value: 1 }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(MatchError);
    expect((err as MatchError).code).toBe('stale');
    expect((err as MatchError).status).toBe(409);
    expect((err as MatchError).snapshot?.version).toBe(9);
    expect(f.calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(f.unmatched).toEqual([]);
  });

  it('stale utan lyckad omhämtning kastar ändå stale, utan snapshot, och varnar', async () => {
    const { f, m } = setup({
      [`POST ${M}/${ID}/actions`]: [json(409, { message: 'Matchen har ändrats.', code: 'stale', version: 9 })],
      [`GET ${M}/${ID}`]: ['network-error'],
    });
    const err = await m.act(snapshotOf(), 'guess', { value: 1 }).catch((e: unknown) => e);
    expect((err as MatchError).code).toBe('stale');
    expect((err as MatchError).snapshot).toBeUndefined();
    expect(console.warn).toHaveBeenCalledWith(expect.stringContaining('kunde inte hämtas igen'), expect.anything());
    expect(f.unmatched).toEqual([]);
  });

  it('404 ger MatchError not_found', async () => {
    const { m } = setup({ [`GET ${M}/${ID}`]: [json(404, { message: 'Not found.' })] });
    const err = await m.get(ID).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(MatchError);
    expect((err as MatchError).code).toBe('not_found');
    expect((err as MatchError).status).toBe(404);
  });

  it('410 ger invite_expired', async () => {
    const { m } = setup({ [`POST ${M}/join`]: [json(410, { message: 'Inbjudan är ogiltig.', code: 'invite_expired' })] });
    const err = await m.join(TOKEN).catch((e: unknown) => e);
    expect((err as MatchError).code).toBe('invite_expired');
    expect((err as MatchError).status).toBe(410);
  });

  it('429 rate_limited och too_many_active skiljs åt', async () => {
    const { m } = setup({
      [`POST ${M}`]: [
        json(429, { message: 'För många anrop.', code: 'rate_limited' }),
        json(429, { message: 'För många matcher.', code: 'too_many_active' }),
      ],
    });
    expect(((await m.create('reveal').catch((e: unknown) => e)) as MatchError).code).toBe('rate_limited');
    expect(((await m.create('reveal').catch((e: unknown) => e)) as MatchError).code).toBe('too_many_active');
  });

  it('403 ruleset_not_allowed och 422 regelfel blir MatchError med koden', async () => {
    const { m } = setup({
      [`POST ${M}`]: [json(403, { message: 'Regeluppsättningen finns inte.', code: 'ruleset_not_allowed' })],
      [`POST ${M}/${ID}/actions`]: [json(422, { message: 'Inte din tur.', code: 'not_your_turn' })],
    });
    const a = (await m.create('nope').catch((e: unknown) => e)) as MatchError;
    expect([a.code, a.status]).toEqual(['ruleset_not_allowed', 403]);
    const b = (await m.act(snapshotOf(), 'guess', { value: 1 }).catch((e: unknown) => e)) as MatchError;
    expect([b.code, b.status]).toEqual(['not_your_turn', 422]);
    expect(b.body).toEqual({ message: 'Inte din tur.', code: 'not_your_turn' });
  });

  it('valideringsfel (422 med errors) blir MatchError validation', async () => {
    const { m } = setup({ [`POST ${M}`]: [json(422, { message: 'Validation failed.', errors: { ruleset: ['krävs'] } })] });
    const err = (await m.create('reveal').catch((e: unknown) => e)) as MatchError;
    expect(err.code).toBe('validation');
  });

  it('5xx förblir ApiError', async () => {
    const { m } = setup({ [`GET ${M}/${ID}`]: [json(500, { message: 'Server Error' })] });
    const err = await m.get(ID).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err).not.toBeInstanceOf(MatchError);
  });
});

describe('createMatches: svarets form', () => {
  it('svar utan version ger "Oväntat svar"', async () => {
    const { version: _v, ...noVersion } = view();
    void _v;
    const { m } = setup({ [`GET ${M}/${ID}`]: [withEtag(200, noVersion, '"7-10"')] });
    await expect(m.get(ID)).rejects.toThrow(`Oväntat svar från GET ${M}/${ID}`);
  });

  it('create utan join_token ger "Oväntat svar"', async () => {
    const { m } = setup({ [`POST ${M}`]: [json(201, { match: view() })] });
    await expect(m.create('reveal')).rejects.toThrow(`Oväntat svar från POST ${M}`);
  });

  it('active med fel form ger "Oväntat svar"', async () => {
    const { m } = setup({ [`GET ${M}/active`]: [json(200, { data: [{ id: ID, ruleset: 'reveal', status: 'active' }] })] });
    await expect(m.active()).rejects.toThrow(`Oväntat svar från GET ${M}/active`);
  });

  it('payload [] och you [] och state [] blir {}; at null godtas', async () => {
    const { m } = setup({
      [`GET ${M}/${ID}`]: [
        withEtag(200, view({ state: [], you: [], events: [{ seq: 1, type: 'expired', seat: null, payload: [], at: null }] }), '"7-00"'),
      ],
    });
    const s = await m.get(ID);
    expect(s.state).toEqual({});
    expect(s.you).toEqual({});
    expect(s.events).toEqual([{ seq: 1, type: 'expired', seat: null, payload: {}, at: null }]);
  });

  it('icke-tomma listor eller andra icke-objekt i state, you och payload avvisas', async () => {
    const { m } = setup({
      [`GET ${M}/${ID}`]: [
        withEtag(200, view({ state: [1] }), '"7-00"'),
        withEtag(200, view({ you: 'x' }), '"7-00"'),
        withEtag(200, view({ events: [{ seq: 1, type: 'left', seat: 1, payload: null, at: null }] }), '"7-00"'),
      ],
    });
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
  });

  it('okänd status, säte utanför 0/1 och fel spelarform avvisas', async () => {
    const { m } = setup({
      [`GET ${M}/${ID}`]: [
        withEtag(200, view({ status: 'paused' }), '"7-00"'),
        withEtag(200, view({ your_seat: 2 }), '"7-00"'),
        withEtag(200, view({ players: [{ seat: 0, name: 'Anna' }] }), '"7-00"'),
      ],
    });
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
    await expect(m.get(ID)).rejects.toThrow('Oväntat svar');
  });
});

describe('assertName', () => {
  it('ogiltigt namn kastar RangeError utan något anrop', async () => {
    const { f, m } = setup({});
    expect(() => m.create('reveal', {}, 'a'.repeat(21))).toThrow(RangeError);
    expect(() => m.join(TOKEN, 'Bo\u0007')).toThrow(RangeError);
    expect(f.calls).toHaveLength(0);
  });

  it('räknar kodpunkter: 20 emoji godtas, 21 avvisas', () => {
    expect(() => assertName('😀'.repeat(20))).not.toThrow();
    expect(() => assertName('😀'.repeat(21))).toThrow(RangeError);
  });

  it('ZWJ-emoji avvisas som på servern (ZWJ är ett formattecken, \\p{Cf})', () => {
    expect(() => assertName('👨‍👩‍👧')).toThrow(RangeError);
  });

  it('namnet trimmas innan det räknas och skickas trimmat', async () => {
    const { f, m } = setup({ [`POST ${M}/join`]: [withEtag(200, view({ your_seat: 1 }), '"7-11"')] });
    expect(() => assertName(`  ${'a'.repeat(20)}  `)).not.toThrow();
    await m.join(TOKEN, '  Bo  ');
    expect(f.calls[0].body).toEqual({ token: TOKEN, name: 'Bo' });
  });

  it('tomt namn efter trim skickas inte (servern gör det till null och ger standardnamnet)', async () => {
    const { f, m } = setup({ [`POST ${M}/join`]: [withEtag(200, view({ your_seat: 1 }), '"7-11"')] });
    expect(() => assertName('   ')).toThrow(RangeError);
    await m.join(TOKEN, '   ');
    expect(f.calls[0].body).toEqual({ token: TOKEN });
  });
});

// ── watch ─────────────────────────────────────────────────────────────────────

type Listener = () => void;

/** Falsk document (miljön är node): visibilityState och lyssnare som testet kan avfyra. */
function fakeDoc(visibilityState: 'visible' | 'hidden' = 'visible') {
  const listeners = new Map<string, Set<Listener>>();
  const doc = {
    visibilityState: visibilityState as DocumentVisibilityState,
    addEventListener(type: string, cb: Listener) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(cb);
    },
    removeEventListener(type: string, cb: Listener) {
      listeners.get(type)?.delete(cb);
    },
  };
  return {
    doc: doc as unknown as Pick<Document, 'visibilityState' | 'addEventListener' | 'removeEventListener'>,
    win: doc as unknown as Pick<Window, 'addEventListener' | 'removeEventListener'>,
    set(state: 'visible' | 'hidden') {
      doc.visibilityState = state;
    },
    fire(type: string) {
      for (const cb of [...(listeners.get(type) ?? [])]) cb();
    },
    count: () => [...listeners.values()].reduce((n, s) => n + s.size, 0),
  };
}

/** Varje watchSetup i testet; afterEach kräver att inget anrop saknade svar. */
const watchFakes: ReturnType<typeof fakeFetch>[] = [];

/**
 * fakeFetch plus en "hängande" variant: medan hang är satt svarar fetch aldrig förrän signalen
 * avbryts. Fångar också init (cache) per anrop.
 */
function watchSetup(routes: Parameters<typeof fakeFetch>[0]) {
  const f = fakeFetch(routes);
  watchFakes.push(f);
  const inits: RequestInit[] = [];
  const state = { hang: false, aborted: 0 };
  const fetch: typeof f.fetch = (input, init) => {
    inits.push(init);
    if (state.hang) {
      return new Promise((_, reject) => {
        init.signal?.addEventListener('abort', () => {
          state.aborted++;
          reject(init.signal?.reason);
        });
      });
    }
    return f.fetch(input, init);
  };
  const auth = createAuth({ fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
  const m = createMatches(GAME, { authed: auth.authed, authedMeta: auth.authedMeta });
  const d = fakeDoc();
  const changes: MatchSnapshot[] = [];
  const errors: { kind: string; terminal: boolean; error: unknown }[] = [];
  const handlers = { onChange: (s: MatchSnapshot) => void changes.push(s), onError: (e: (typeof errors)[number]) => void errors.push(e) };
  const watchOpts = { doc: d.doc, win: d.win };
  return { f, m, d, inits, state, changes, errors, handlers, watchOpts };
}

const POLL = `GET ${M}/${ID}`;
const tick = (ms = 0) => vi.advanceTimersByTimeAsync(ms);

describe('watch', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
  });
  afterEach(() => {
    vi.useRealTimers();
    const fakes = watchFakes.splice(0);
    for (const f of fakes) expect(f.unmatched).toEqual([]);
  });

  it('första pollen direkt, sedan var 1500 ms, med cache no-store', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [new Response(null, { status: 304 })] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.f.calls).toHaveLength(1);
    expect(t.changes).toHaveLength(1);
    expect(t.inits[0].cache).toBe('no-store');
    await tick(1499);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    w.stop();
    expect(t.f.unmatched).toEqual([]);
  });

  it('304 ger inget onChange; ETag och since skickas', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [new Response(null, { status: 304 })] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(1500);
    expect(t.f.calls[1].headers['If-None-Match']).toBe('"7-10"');
    expect(t.changes).toHaveLength(1);
    w.stop();
    expect(t.f.unmatched).toEqual([]);
  });

  it('ETag från act används i nästa poll', async () => {
    const t = watchSetup({
      [POLL]: [withEtag(200, view(), '"7-10"')],
      [`POST ${M}/${ID}/actions`]: [withEtag(200, view({ version: 8, events: [{ seq: 13, type: 'guess', seat: 0, payload: {}, at: null }] }), '"8-11"')],
      [`${POLL}?since=13`]: [new Response(null, { status: 304 })],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await t.m.act(t.changes[0], 'guess', { value: 1 });
    await tick(1500);
    const poll = t.f.calls[2];
    expect(poll.path).toBe(`${M}/${ID}?since=13`);
    expect(poll.headers['If-None-Match']).toBe('"8-11"');
    w.stop();
    expect(t.f.unmatched).toEqual([]);
  });

  it('en poll med lägre version än act gav kastas; act:s version kommer i stället', async () => {
    const t = watchSetup({
      [POLL]: [withEtag(200, view(), '"7-10"')],
      [`POST ${M}/${ID}/actions`]: [withEtag(200, view({ version: 8, events: [] }), '"8-11"')],
      // Ett mellanlager har ett gammalt svar: version 7 trots If-None-Match.
      [`${POLL}?since=12`]: [withEtag(200, view({ version: 7, events: [] }), '"7-11"')],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await t.m.act(t.changes[0], 'guess', { value: 1 });
    await tick(1500);
    expect(t.f.calls).toHaveLength(3);
    expect(t.changes.map((c) => c.version)).toEqual([7, 8]);
    w.stop();
    expect(t.f.unmatched).toEqual([]);
  });

  it('händelser slås ihop utan dubbletter, sorterade på seq', async () => {
    const ev = (seq: number) => ({ seq, type: 'guess', seat: 0, payload: { n: seq }, at: null });
    const t = watchSetup({
      [POLL]: [withEtag(200, view({ events: [ev(11), ev(12)] }), '"7-10"')],
      [`${POLL}?since=12`]: [withEtag(200, view({ version: 9, events: [ev(12), ev(14), ev(13)] }), '"9-10"')],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(1500);
    expect(t.changes[1].events.map((e) => e.seq)).toEqual([11, 12, 13, 14]);
    w.stop();
    expect(t.f.unmatched).toEqual([]);
  });

  it('429 med Retry-After: 3 väntar 3000 ms och rapporteras som rate_limited', async () => {
    const limited = new Response(JSON.stringify({ message: 'För många anrop.', code: 'rate_limited' }), {
      status: 429,
      headers: { 'Content-Type': 'application/json', 'Retry-After': '3' },
    });
    const t = watchSetup({ [POLL]: [limited, withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors).toEqual([{ kind: 'rate_limited', terminal: false, error: expect.any(MatchError) }]);
    await tick(2999);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    expect(t.changes).toHaveLength(1);
    w.stop();
  });

  it('nätfel: onError en gång, backoff 5000 ms, sedan onChange när det fungerar igen', async () => {
    const t = watchSetup({
      [POLL]: ['network-error', 'network-error', withEtag(200, view(), '"7-10"')],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors).toHaveLength(1);
    expect(t.errors[0]).toMatchObject({ kind: 'network', terminal: false });
    await tick(4999);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    expect(t.errors).toHaveLength(1); // andra felet i serien inom 10 s rapporteras inte
    await tick(5000);
    expect(t.f.calls).toHaveLength(3);
    expect(t.changes).toHaveLength(1);
    w.stop();
  });

  it('en lång felserie rapporteras högst var 10:e sekund', async () => {
    const t = watchSetup({ [POLL]: ['network-error'] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(5000);
    await tick(5000);
    expect(t.f.calls).toHaveLength(3);
    expect(t.errors).toHaveLength(2); // t=0 och t=10000
    w.stop();
  });

  it('5xx ger server, icke-terminal', async () => {
    const t = watchSetup({ [POLL]: [json(503, { message: 'Down' })] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors[0]).toMatchObject({ kind: 'server', terminal: false });
    w.stop();
  });

  it('återhämtning efter fel ger onChange även på 304', async () => {
    const t = watchSetup({
      [POLL]: [withEtag(200, view(), '"7-10"')],
      [`${POLL}?since=12`]: ['network-error', new Response(null, { status: 304 })],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(1500);
    expect(t.errors).toHaveLength(1);
    await tick(5000);
    expect(t.changes).toHaveLength(2);
    expect(t.changes[1].version).toBe(7);
    w.stop();
  });

  it('fel form ger shape, icke-terminal', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, { nope: true }, '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors[0]).toMatchObject({ kind: 'shape', terminal: false });
    w.stop();
  });

  it('404 ger gone, terminal, och inga fler anrop', async () => {
    const t = watchSetup({ [POLL]: [json(404, { message: 'Not found.' })] });
    t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors).toEqual([{ kind: 'gone', terminal: true, error: expect.any(MatchError) }]);
    await tick(60_000);
    expect(t.f.calls).toHaveLength(1);
    expect(t.d.count()).toBe(0);
  });

  it('abandoned ger onChange och sedan gone, terminal', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view({ status: 'abandoned' }), '"7-00"')] });
    t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.changes.map((s) => s.status)).toEqual(['abandoned']);
    expect(t.errors).toMatchObject([{ kind: 'gone', terminal: true }]);
    await tick(60_000);
    expect(t.f.calls).toHaveLength(1);
  });

  it('finished pollas var 5000 ms', async () => {
    const t = watchSetup({
      [POLL]: [withEtag(200, view({ status: 'finished' }), '"7-11"')],
      [`${POLL}?since=12`]: [new Response(null, { status: 304 })],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(4999);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    await tick(5000);
    expect(t.f.calls).toHaveLength(3);
    w.stop();
  });

  it('rematch-händelse ger onChange och sedan stopp', async () => {
    const NEW = '01JB8Z3Q4WXYZ0123456789ABD';
    const t = watchSetup({
      [POLL]: [withEtag(200, view({ status: 'finished' }), '"7-11"')],
      [`${POLL}?since=12`]: [
        withEtag(200, view({ status: 'finished', version: 9, events: [{ seq: 13, type: 'rematch', seat: null, payload: { match_id: NEW }, at: null }] }), '"9-11"'),
      ],
    });
    t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(5000);
    expect(t.changes).toHaveLength(2);
    expect(t.changes[1].events.at(-1)).toMatchObject({ type: 'rematch', payload: { match_id: NEW } });
    expect(t.errors).toEqual([]);
    await tick(60_000);
    expect(t.f.calls).toHaveLength(2);
    expect(t.d.count()).toBe(0);
  });

  it('rematch som spelarens eget anrop gav (pollen svarar 304) ger ändå onChange och stopp', async () => {
    const NEW = '01JB8Z3Q4WXYZ0123456789ABD';
    const t = watchSetup({
      [POLL]: [withEtag(200, view({ status: 'finished' }), '"7-11"')],
      [`POST ${M}/${ID}/rematch`]: [
        withEtag(200, view({ status: 'finished', version: 9, events: [{ seq: 13, type: 'rematch', seat: null, payload: { match_id: NEW }, at: null }] }), '"9-11"'),
      ],
      [`${POLL}?since=13`]: [new Response(null, { status: 304 })],
    });
    t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await t.m.rematch(ID);
    await tick(5000);
    expect(t.changes).toHaveLength(2);
    expect(t.changes[1].events.at(-1)?.type).toBe('rematch');
    await tick(60_000);
    expect(t.f.calls).toHaveLength(3);
    expect(t.f.unmatched).toEqual([]);
  });

  it('dold flik avbryter pågående poll utan onError; ingen poll medan dold; synlig pollar direkt', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')] });
    t.state.hang = true;
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.inits).toHaveLength(1);
    t.d.set('hidden');
    t.d.fire('visibilitychange');
    await tick();
    expect(t.state.aborted).toBe(1);
    expect(t.errors).toEqual([]);
    await tick(60_000);
    expect(t.inits).toHaveLength(1);
    t.state.hang = false;
    t.d.set('visible');
    t.d.fire('visibilitychange');
    await tick();
    expect(t.inits).toHaveLength(2);
    expect(t.changes).toHaveLength(1);
    w.stop();
  });

  it('dold vid start ger inga anrop; pageshow och online pollar direkt när synlig', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [new Response(null, { status: 304 })] });
    t.d.set('hidden');
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick(10_000);
    expect(t.f.calls).toHaveLength(0);
    t.d.set('visible');
    t.d.fire('pageshow');
    await tick();
    expect(t.f.calls).toHaveLength(1);
    t.d.fire('online');
    await tick();
    expect(t.f.calls).toHaveLength(2);
    w.stop();
  });

  it('två pollNow samtidigt ger ett anrop', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    w.pollNow();
    w.pollNow();
    await tick();
    expect(t.f.calls).toHaveLength(1);
    w.stop();
  });

  it('stop stoppar allt: lyssnare, timer och pågående poll, och inga handtag efteråt', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')] });
    t.state.hang = true;
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    w.stop();
    await tick();
    expect(t.state.aborted).toBe(1);
    expect(t.d.count()).toBe(0);
    w.pollNow();
    t.d.fire('online');
    await tick(60_000);
    expect(t.inits).toHaveLength(1);
    expect(t.changes).toEqual([]);
    expect(t.errors).toEqual([]);
  });

  it('stop medan ett svar är på väg ger inget onChange', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    w.stop();
    await tick(60_000);
    expect(t.changes).toEqual([]);
    expect(t.errors).toEqual([]);
  });

  it('en ögonblicksbild från omhämtningen efter stale når onChange en gång trots 304', async () => {
    const t = watchSetup({
      [POLL]: [
        withEtag(200, view({ version: 8 }), '"8-11"'),
        withEtag(200, view({ version: 9, events: [{ seq: 13, type: 'guess', seat: 1, payload: {}, at: null }] }), '"9-11"'),
      ],
      [`POST ${M}/${ID}/actions`]: [json(409, { message: 'Matchen har ändrats.', code: 'stale', version: 9 })],
      [`${POLL}?since=13`]: [new Response(null, { status: 304 })],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.changes.map((c) => c.version)).toEqual([8]);
    const err = (await t.m.act(t.changes[0], 'guess', { value: 1 }).catch((e: unknown) => e)) as MatchError;
    expect(err.snapshot?.version).toBe(9);
    await tick(1500);
    expect(t.f.calls.at(-1)?.headers['If-None-Match']).toBe('"9-11"');
    expect(t.changes.map((c) => c.version)).toEqual([8, 9]);
    expect(t.changes[1].events.map((e) => e.seq)).toEqual([12, 13]);
    await tick(1500);
    expect(t.changes).toHaveLength(2);
    w.stop();
  });

  it('Retry-After begränsas: 0 ger 1000 ms, 3600 ger 60000 ms', async () => {
    const limited = (retryAfter: string) =>
      new Response(JSON.stringify({ message: 'För många anrop.', code: 'rate_limited' }), {
        status: 429,
        headers: { 'Content-Type': 'application/json', 'Retry-After': retryAfter },
      });
    const t = watchSetup({ [POLL]: [limited('0'), limited('3600'), withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(999);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    await tick(59_999);
    expect(t.f.calls).toHaveLength(2);
    await tick(1);
    expect(t.f.calls).toHaveLength(3);
    w.stop();
  });

  it('Retry-After som inte är ett tal (abc) eller är orimligt stort ger vanlig backoff, 5000 ms', async () => {
    const limited = (retryAfter: string) =>
      new Response(JSON.stringify({ message: 'För många anrop.', code: 'rate_limited' }), {
        status: 429,
        headers: { 'Content-Type': 'application/json', 'Retry-After': retryAfter },
      });
    const t = watchSetup({ [POLL]: [limited('abc'), limited('9'.repeat(400)), withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.errors).toEqual([{ kind: 'rate_limited', terminal: false, error: expect.any(MatchError) }]);
    await tick(4999);
    expect(t.f.calls).toHaveLength(1);
    await tick(1);
    expect(t.f.calls).toHaveLength(2);
    await tick(4999);
    expect(t.f.calls).toHaveLength(2);
    await tick(1);
    expect(t.f.calls).toHaveLength(3);
    expect(t.changes).toHaveLength(1);
    w.stop();
  });

  it('ett icke-ändligt ApiError.retryAfter (NaN, Infinity) ger vanlig backoff', async () => {
    for (const bad of [Number.NaN, Number.POSITIVE_INFINITY]) {
      let n = 0;
      const authedMeta = (async () => {
        n++;
        throw new ApiError(429, 'För många anrop.', { message: 'x' }, 'GET', `${M}/${ID}`, bad);
      }) as unknown as Parameters<typeof createMatches>[1]['authedMeta'];
      const m = createMatches(GAME, { authed: (async () => ({})) as unknown as Parameters<typeof createMatches>[1]['authed'], authedMeta });
      const errors: string[] = [];
      const d = fakeDoc();
      const w = m.watch(ID, { onChange: () => {}, onError: (e) => void errors.push(e.kind) }, { doc: d.doc, win: d.win });
      await tick();
      expect(n).toBe(1);
      await tick(4999);
      expect(n).toBe(1);
      await tick(1);
      expect(n).toBe(2);
      expect(errors[0]).toBe('rate_limited');
      w.stop();
    }
  });

  it('kan ögonblicksbilden inte kopieras går felet till onError (shape), och bevakningen fortsätter', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [new Response(null, { status: 304 })] });
    const real = JSON.stringify;
    let broken = 1;
    vi.spyOn(JSON, 'stringify').mockImplementation(function (this: unknown, ...args: Parameters<typeof JSON.stringify>) {
      const v = args[0] as Record<string, unknown> | null;
      if (broken > 0 && typeof v === 'object' && v !== null && 'ruleset' in v && 'yourSeat' in v) {
        broken--;
        throw new TypeError('kan inte kopieras');
      }
      return real.apply(JSON, args);
    } as typeof JSON.stringify);
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.changes).toEqual([]);
    expect(t.errors).toEqual([{ kind: 'shape', terminal: false, error: expect.any(TypeError) }]);
    await tick(5000);
    expect(t.f.calls).toHaveLength(2);
    expect(t.changes.map((c) => c.version)).toEqual([7]);
    expect(t.errors).toHaveLength(1);
    w.stop();
  });

  it('pollNow väntar ut backoff; online går före', async () => {
    const t = watchSetup({ [POLL]: ['network-error', withEtag(200, view(), '"7-10"')] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    expect(t.f.calls).toHaveLength(1);
    await tick(100);
    w.pollNow();
    await tick();
    expect(t.f.calls).toHaveLength(1);
    t.d.fire('online');
    await tick();
    expect(t.f.calls).toHaveLength(2);
    expect(t.changes).toHaveLength(1);
    w.stop();
  });

  it('pollNow efter backoffens slut pollar direkt', async () => {
    const t = watchSetup({ [POLL]: ['network-error', withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [new Response(null, { status: 304 })] });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    await tick(5000);
    expect(t.f.calls).toHaveLength(2);
    w.pollNow();
    await tick();
    expect(t.f.calls).toHaveLength(3);
    w.stop();
  });

  it('onChange får en fryst kopia: spelet kan inte ändra nästa sammanslagning', async () => {
    const ev = (seq: number) => ({ seq, type: 'guess', seat: 0 as const, payload: { n: seq }, at: null });
    const t = watchSetup({
      [POLL]: [withEtag(200, view({ events: [ev(11), ev(12)] }), '"7-10"')],
      [`${POLL}?since=12`]: [withEtag(200, view({ version: 8, events: [ev(13)] }), '"8-10"')],
    });
    const w = t.m.watch(ID, t.handlers, t.watchOpts);
    await tick();
    const first = t.changes[0];
    expect(Object.isFrozen(first)).toBe(true);
    expect(() => first.events.push(ev(99))).toThrow(TypeError);
    expect(() => {
      (first.events[0].payload as Record<string, unknown>).n = 0;
    }).toThrow(TypeError);
    expect(() => {
      (first.players as unknown[]).length = 0;
    }).toThrow(TypeError);
    await tick(1500);
    expect(t.changes[1].events).toEqual([ev(11), ev(12), ev(13)]);
    expect(t.changes[1].players).toHaveLength(2);
    w.stop();
  });

  it('ett onChange som kastar stoppar inte bevakningen', async () => {
    const t = watchSetup({ [POLL]: [withEtag(200, view(), '"7-10"')], [`${POLL}?since=12`]: [withEtag(200, view({ version: 8, events: [] }), '"8-10"')] });
    let n = 0;
    const w = t.m.watch(ID, { onChange: () => { n++; throw new Error('spelet'); }, onError: t.handlers.onError }, t.watchOpts);
    await tick();
    await tick(1500);
    expect(n).toBe(2);
    expect(console.error).toHaveBeenCalledWith(expect.stringContaining('onChange'), expect.any(Error));
    w.stop();
  });
});

// ── Inbjudningslänken ─────────────────────────────────────────────────────────

function fakeLocation(pathname: string, search: string, hash: string) {
  const loc = { pathname, search, hash };
  const replaced: string[] = [];
  const hist = {
    state: { keep: true },
    replaceState(_state: unknown, _unused: string, url?: string | URL | null) {
      replaced.push(String(url));
    },
  };
  return { loc, hist, replaced };
}

describe('inviteUrl', () => {
  it('bygger en fragmentlänk med kodad token', () => {
    expect(inviteUrl(TOKEN, 'https://duell.computercat.co')).toBe(`https://duell.computercat.co/#join=${TOKEN}`);
    expect(inviteUrl('a b/c', 'https://duell.computercat.co/')).toBe('https://duell.computercat.co/#join=a%20b%2Fc');
  });

  it('localhost och 127.0.0.1 över http godtas för utveckling', () => {
    expect(inviteUrl(TOKEN, 'http://localhost:3000')).toBe(`http://localhost:3000/#join=${TOKEN}`);
    expect(inviteUrl(TOKEN, 'http://127.0.0.1')).toBe(`http://127.0.0.1/#join=${TOKEN}`);
    expect(inviteUrl(TOKEN, 'http://127.0.0.1:3000')).toBe(`http://127.0.0.1:3000/#join=${TOKEN}`);
  });

  it('capacitor://localhost (iOS-appen) kastar: spelets publika adress måste skickas in', () => {
    expect(() => inviteUrl(TOKEN, 'capacitor://localhost')).toThrow(/publika webbadress/);
    expect(() => inviteUrl(TOKEN, 'http://localhost.evil.example')).toThrow(/publika webbadress/);
    expect(() => inviteUrl(TOKEN, 'http://127.0.0.1.evil.example')).toThrow(/publika webbadress/);
    expect(() => inviteUrl(TOKEN, 'http://127.0.0.10')).toThrow(/publika webbadress/);
    expect(() => inviteUrl(TOKEN, 'http://duell.computercat.co')).toThrow(/publika webbadress/);
  });

  it('utan origin och utan window kastar (miljön är node)', () => {
    expect(() => inviteUrl(TOKEN)).toThrow(/publika webbadress/);
  });
});

describe('readJoinToken', () => {
  it('läser fragmentet och tar bort det', () => {
    const { loc, hist, replaced } = fakeLocation('/spela', '', `#join=${TOKEN}`);
    expect(readJoinToken(loc, hist)).toBe(TOKEN);
    expect(replaced).toEqual(['/spela']);
  });

  it('behåller övriga delar av fragmentet', () => {
    const { loc, hist, replaced } = fakeLocation('/', '', `#join=${TOKEN}&tab=2`);
    expect(readJoinToken(loc, hist)).toBe(TOKEN);
    expect(replaced).toEqual(['/#tab=2']);
  });

  it('läser ?join= (bakåtkompatibelt) och tar bort det, med andra parametrar och fragment kvar', () => {
    const { loc, hist, replaced } = fakeLocation('/', `?ref=qr&join=${TOKEN}&x=1`, '#regler');
    expect(readJoinToken(loc, hist)).toBe(TOKEN);
    expect(replaced).toEqual(['/?ref=qr&x=1#regler']);
  });

  it('ogiltig token ger null och tas ändå bort', () => {
    const { loc, hist, replaced } = fakeLocation('/', '?a=1', '#join=kort');
    expect(readJoinToken(loc, hist)).toBeNull();
    expect(replaced).toEqual(['/?a=1']);
  });

  it('token med otillåtna tecken (43 långa) ger null', () => {
    const { loc, hist } = fakeLocation('/', '', `#join=${TOKEN.slice(0, 42)}%2F`);
    expect(readJoinToken(loc, hist)).toBeNull();
  });

  it('trasig procentkodning ger null och tas bort', () => {
    const { loc, hist, replaced } = fakeLocation('/', '', '#join=%E0%A4%A');
    expect(readJoinToken(loc, hist)).toBeNull();
    expect(replaced).toEqual(['/']);
  });

  it('ingen token ger null och inget replaceState', () => {
    const { loc, hist, replaced } = fakeLocation('/', '?join2=x', '#joinx=y');
    expect(readJoinToken(loc, hist)).toBeNull();
    expect(replaced).toEqual([]);
  });

  it('utan location (miljön är node) ger null', () => {
    expect(readJoinToken()).toBeNull();
  });
});
