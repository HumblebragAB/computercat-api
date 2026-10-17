import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError } from './http';
import { MatchError, assertName, createMatches, type MatchSnapshot } from './matches';

const GAME = 'duell';
const ID = '01JB8Z3Q4WXYZ0123456789ABC';
const M = `/games/${GAME}/matches`;
const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd';

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
