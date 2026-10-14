import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { memoryStorage } from './storage';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('computercat', () => {
  it('import gör inga nätverksanrop och getComputercat vägrar utanför webbläsaren', async () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    const mod = await import('./index');
    expect(fetchSpy).not.toHaveBeenCalled();
    const r = mod.getComputercat();
    expect(r.ok).toBe(false);
  });

  it('recordScore köar, skapar spelare och skickar till topplistan', async () => {
    const { createComputercat } = await import('./index');
    const f = fakeFetch({
      'POST /auth/anonymous': [
        json(201, { user: { id: 9, display_name: 'Player-9', is_anonymous: true, created_at: null }, token: '9|t' }),
      ],
      'POST /games/kattsvep/leaderboards/daily-clicks': [
        json(201, { data: { rank: null, score: 14, metadata: null, submitted_at: 'x', user: { id: 9, display_name: 'Player-9' } } }),
      ],
    });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: memoryStorage() });
    const { item, flushed } = cc.recordScore('daily-clicks', 14, { round_ms: 10000 });
    expect(item.score).toBe(14);
    expect(await flushed).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(f.unmatched).toEqual([]);
  });

  it('standard-fetch slås upp vid anropet, inte vid skapandet (undviker "Illegal invocation")', async () => {
    const { createComputercat } = await import('./index');
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, storage: memoryStorage() });
    const later = vi.fn(async () =>
      json(201, { user: { id: 1, display_name: 'P', is_anonymous: true, created_at: null }, token: '1|x' }),
    );
    vi.stubGlobal('fetch', later);
    await cc.auth.getSession();
    expect(later).toHaveBeenCalledTimes(1);
  });
});
