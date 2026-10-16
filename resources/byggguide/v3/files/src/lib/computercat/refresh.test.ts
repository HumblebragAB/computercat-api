import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';

const GAME = 'kattsvep';
const KEY = `computercat:${GAME}:session`;
const HOUR = 3_600_000;
const NOW = Date.parse('2026-10-13T12:00:00Z');
const OLD = NOW - 25 * HOUR;
const user = (id: number, name: string) => ({ id, display_name: name, is_anonymous: true, created_at: null });
const refreshed = (token: string, id = 7, name = 'Mia') =>
  json(200, { token, user: user(id, name), expires_at: '2026-10-20T12:00:00Z' });
const stored = (storage: ReturnType<typeof storedSession>) => JSON.parse(storage.getItem(KEY)!);

afterEach(() => vi.restoreAllMocks());

function make(f: ReturnType<typeof fakeFetch>, storage: ReturnType<typeof storedSession>) {
  return createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage, now: () => NOW });
}

describe('tokenförnyelse vid start', () => {
  it('token yngre än 24 h förnyas inte', async () => {
    const f = fakeFetch({});
    const auth = make(f, storedSession(GAME, '1|ny', NOW - 23 * HOUR));
    expect((await auth.getSession()).token).toBe('1|ny');
    expect(f.calls).toHaveLength(0);
  });

  it('token äldre än 24 h förnyas en gång och ersätter den sparade', async () => {
    const f = fakeFetch({ 'POST /auth/refresh': [refreshed('2|fräsch')] });
    const storage = storedSession(GAME, '1|gammal', OLD);
    const auth = make(f, storage);
    const [a, b] = await Promise.all([auth.getSession(), auth.getSession()]);
    expect(a.token).toBe('2|fräsch');
    expect(b.token).toBe('2|fräsch');
    expect(f.calls).toHaveLength(1);
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|gammal');
    expect(stored(storage)).toEqual({ token: '2|fräsch', userId: 7, displayName: 'Mia', issuedAt: NOW });
    await auth.getSession();
    expect(f.calls).toHaveLength(1);
  });

  it('404 (ej driftsatt) behåller token, varnar en gång och försöker inte igen', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /auth/refresh': [json(404, { message: 'Not Found' })] });
    const storage = storedSession(GAME, '1|gammal', OLD);
    const auth = make(f, storage);
    expect((await auth.getSession()).token).toBe('1|gammal');
    await auth.getSession();
    expect(f.calls).toHaveLength(1);
    expect(stored(storage).token).toBe('1|gammal');
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it('nätverksfel behåller token', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /auth/refresh': ['network-error'] });
    const storage = storedSession(GAME, '1|gammal', OLD);
    const auth = make(f, storage);
    expect((await auth.getSession()).token).toBe('1|gammal');
    expect(stored(storage).token).toBe('1|gammal');
    expect(f.calls.some((c) => c.path === '/auth/anonymous')).toBe(false);
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it('500 behåller token', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /auth/refresh': [json(500, { message: 'Server Error' })] });
    const auth = make(f, storedSession(GAME, '1|gammal', OLD));
    expect((await auth.getSession()).token).toBe('1|gammal');
  });

  it('401 följer vägen för ny anonym spelare', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'POST /auth/refresh': [json(401, { message: 'Unauthenticated.' })],
      'POST /auth/anonymous': [json(201, { user: user(43, 'Player-43'), token: '6|ny' })],
    });
    const storage = storedSession(GAME, '1|gammal', OLD);
    const auth = make(f, storage);
    const s = await auth.getSession();
    expect(s).toEqual({ token: '6|ny', userId: 43, displayName: 'Player-43', issuedAt: NOW });
    expect(stored(storage).token).toBe('6|ny');
    expect(f.calls.map((c) => c.path)).toEqual(['/auth/refresh', '/auth/anonymous']);
  });

  it('två flikar som förnyar samtidigt: lagringen håller en token och båda använder den', async () => {
    const storage = storedSession(GAME, '1|gammal', OLD);
    const fa = fakeFetch({ 'POST /auth/refresh': [refreshed('2|från-a')] });
    const fb = fakeFetch({ 'POST /auth/refresh': [refreshed('3|från-b')] });
    const a = make(fa, storage);
    const b = make(fb, storage);
    const [sa, sb] = await Promise.all([a.getSession(), b.getSession()]);
    const inStorage = stored(storage).token;
    expect(inStorage).toBe('2|från-a');
    expect(sa.token).toBe(inStorage);
    expect(sb.token).toBe(inStorage);
  });

  it('flik som förnyar medan en annan redan bytt token skriver inte över den', async () => {
    const storage = storedSession(GAME, '1|gammal', OLD);
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const fa = fakeFetch({
      'POST /auth/refresh': [() => refreshed('2|från-a')],
    });
    const slow = {
      ...fa,
      fetch: async (input: string, init: RequestInit) => {
        await gate;
        return fa.fetch(input, init);
      },
    };
    const a = createAuth({ fetch: slow.fetch, baseUrl: BASE, game: GAME, storage, now: () => NOW });
    const pending = a.getSession();
    // Flik B hann före och sparade sin token medan A:s anrop var på väg.
    storage.setItem(KEY, JSON.stringify({ token: '9|från-b', userId: 7, displayName: 'Mia', issuedAt: NOW }));
    release();
    expect((await pending).token).toBe('9|från-b');
    expect(stored(storage).token).toBe('9|från-b');
  });
});
