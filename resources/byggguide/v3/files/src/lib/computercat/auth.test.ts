import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError, NetworkError } from './http';
import { memoryStorage } from './storage';

const GAME = 'kattsvep';
const KEY = `computercat:${GAME}:session`;
const anonymous = (id: number, token: string) =>
  json(201, { user: { id, display_name: `Player-${id}`, is_anonymous: true, created_at: '2026-10-13T10:00:00Z' }, token });

afterEach(() => vi.restoreAllMocks());

describe('createAuth', () => {
  it('skapar en anonym spelare första gången och sparar token', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [anonymous(42, '5|ny')] });
    const storage = memoryStorage();
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage, now: () => 1_000 });
    const s = await auth.getSession();
    expect(s).toEqual({ token: '5|ny', userId: 42, displayName: 'Player-42', issuedAt: 1_000 });
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual(s);
    expect(f.unmatched).toEqual([]);
  });

  it('återanvänder sparad token mellan sidladdningar', async () => {
    const f = fakeFetch({});
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    expect((await auth.getSession()).token).toBe('1|stored');
    expect(f.calls).toHaveLength(0);
  });

  it('två samtidiga anrop skapar bara en spelare', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [anonymous(42, '5|ny'), anonymous(43, '6|dubblett')] });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: memoryStorage() });
    const [a, b] = await Promise.all([auth.getSession(), auth.getSession()]);
    expect(a.token).toBe('5|ny');
    expect(b.token).toBe('5|ny');
    expect(f.calls.filter((c) => c.path === '/auth/anonymous')).toHaveLength(1);
  });

  it('verify läser user ur GET /auth/me och skapar ingen ny spelare', async () => {
    const f = fakeFetch({
      'GET /auth/me': [json(200, { user: { id: 7, display_name: 'Mia', is_anonymous: true, created_at: null } })],
    });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    const s = await auth.verify();
    expect(s).toEqual({ token: '1|stored', userId: 7, displayName: 'Mia' });
    expect(f.calls.map((c) => c.path)).toEqual(['/auth/me']);
  });

  it('401 ger en ny anonym spelare och anropet görs om en gång', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /games/kattsvep/leaderboards/daily': [
        json(401, { message: 'Unauthenticated.' }),
        json(200, { data: [], period_key: '2026-10-13' }),
      ],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const storage = storedSession(GAME, '1|gammal');
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    const res = await auth.authed<{ period_key: string }>('GET', '/games/kattsvep/leaderboards/daily');
    expect(res.period_key).toBe('2026-10-13');
    expect(f.calls.map((c) => `${c.method} ${c.path} ${c.headers.Authorization ?? '-'}`)).toEqual([
      'GET /games/kattsvep/leaderboards/daily Bearer 1|gammal',
      'POST /auth/anonymous -',
      'GET /games/kattsvep/leaderboards/daily Bearer 6|ny',
    ]);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('6|ny');
  });

  it('nätverksfel behåller token (offline är inte utloggad)', async () => {
    const f = fakeFetch({ 'GET /auth/me': ['network-error'] });
    const storage = storedSession(GAME);
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.verify()).rejects.toBeInstanceOf(NetworkError);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('1|stored');
    expect(f.calls.some((c) => c.path === '/auth/anonymous')).toBe(false);
  });

  it('500 behåller token', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /auth/me': [json(500, { message: 'Server Error' })] });
    const storage = storedSession(GAME);
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.verify()).rejects.toMatchObject({ status: 500 });
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('1|stored');
  });

  it('två 401 i rad ger fel, ingen loop', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /auth/me': [json(401, { message: 'Unauthenticated.' })],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    const err = await auth.verify().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(401);
    expect(f.calls.filter((c) => c.path === '/auth/anonymous')).toHaveLength(1);
    expect(f.calls.filter((c) => c.path === '/auth/me')).toHaveLength(2);
  });

  it('oväntat svar från /auth/anonymous ger tydligt fel och sparar inget', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [json(201, { data: { id: 1 } })] });
    const storage = memoryStorage();
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.getSession()).rejects.toThrow('POST /auth/anonymous');
    expect(storage.getItem(KEY)).toBeNull();
  });

  it('två flikar: B:s 401 med gammal token tar över A:s nya token, ingen andra spelare', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const storage = storedSession(GAME, '1|gammal');
    const fa = fakeFetch({ 'POST /auth/anonymous': [anonymous(43, '6|a-ny')] });
    const fb = fakeFetch({
      'GET /auth/me': [
        (req) => (req.headers.Authorization === 'Bearer 1|gammal'
          ? json(401, { message: 'Unauthenticated.' })
          : json(200, { user: { id: 43, display_name: 'Player-43', is_anonymous: true, created_at: null } })),
      ],
    });
    const b = createAuth({ fetch: fb.fetch, baseUrl: BASE, game: GAME, storage });
    // A startar efter att den gamla token rensats och skapar en ny spelare; B har fortfarande den gamla i minnet.
    storage.removeItem(KEY);
    const a = createAuth({ fetch: fa.fetch, baseUrl: BASE, game: GAME, storage });
    await a.getSession();
    const s = await b.verify();
    expect(s.token).toBe('6|a-ny');
    expect(fb.calls.some((c) => c.path === '/auth/anonymous')).toBe(false);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('6|a-ny');
  });

  it('authedMeta: 401 ger en ny anonym spelare och anropet görs om en gång med samma headers', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /matches/9': [
        json(401, { message: 'Unauthenticated.' }),
        new Response(JSON.stringify({ data: { version: 4 } }), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"4-12"' } }),
      ],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const storage = storedSession(GAME, '1|gammal');
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    const res = await auth.authedMeta<{ data: { version: number } }>('GET', '/matches/9', { headers: { 'If-None-Match': '"3-11"' } });
    expect(res).toEqual({ status: 200, body: { data: { version: 4 } }, etag: '"4-12"' });
    expect(f.calls.map((c) => `${c.method} ${c.path} ${c.headers.Authorization ?? '-'} ${c.headers['If-None-Match'] ?? '-'}`)).toEqual([
      'GET /matches/9 Bearer 1|gammal "3-11"',
      'POST /auth/anonymous - -',
      'GET /matches/9 Bearer 6|ny "3-11"',
    ]);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('6|ny');
    expect(f.unmatched).toEqual([]);
  });

  it('authedMeta: två 401 i rad ger fel, ingen loop', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /matches/9': [json(401, { message: 'Unauthenticated.' })],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    const err = await auth.authedMeta('GET', '/matches/9').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(401);
    expect(f.calls.filter((c) => c.path === '/matches/9')).toHaveLength(2);
    expect(f.calls.filter((c) => c.path === '/auth/anonymous')).toHaveLength(1);
  });

  it('authedMeta: nätverksfel behåller token', async () => {
    const f = fakeFetch({ 'GET /matches/9': ['network-error'] });
    const storage = storedSession(GAME);
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.authedMeta('GET', '/matches/9')).rejects.toBeInstanceOf(NetworkError);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('1|stored');
    expect(f.calls.some((c) => c.path === '/auth/anonymous')).toBe(false);
  });
});
