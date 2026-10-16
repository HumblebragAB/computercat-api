import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
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

  it('flushAll tömmer resultatkön och achievementkön och meddelar onFlushed', async () => {
    const { createComputercat } = await import('./index');
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({
      'POST /games/kattsvep/leaderboards/daily-clicks': [
        'network-error',
        json(201, { data: { rank: 1, score: 5, metadata: null, submitted_at: 'x', user: { id: 7, display_name: 'Player-7' } } }),
      ],
      'POST /games/kattsvep/achievements': ['network-error', json(201, { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] })],
    });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    expect((await cc.recordScore('daily-clicks', 5).flushed).remaining).toBe(1);
    expect((await cc.achievements.report(['first-log']).flushed).remaining).toBe(1);

    const seen: unknown[] = [];
    const stop = cc.onFlushed((r) => seen.push(r));
    const result = await cc.flushAll();
    expect(result.scores).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(result.achievements).toMatchObject({ sent: 1, dropped: [], remaining: 0 });
    expect(seen).toEqual([result]);

    stop();
    await cc.flushAll();
    expect(seen).toHaveLength(1);
    expect(f.unmatched).toEqual([]);
  });

  it('flushAll med tomma köer gör inga anrop', async () => {
    const { createComputercat } = await import('./index');
    const f = fakeFetch({});
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    const r = await cc.flushAll();
    expect(r.scores).toEqual({ sent: 0, dropped: [], remaining: 0 });
    expect(r.achievements.remaining).toBe(0);
    expect(f.calls).toEqual([]);
  });

  it('kastar resultatkön töms achievementkön ändå, och flushAll avvisas med felet', async () => {
    const { createComputercat } = await import('./index');
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /games/kattsvep/achievements': ['network-error', json(201, { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] })] });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    expect((await cc.achievements.report(['first-log']).flushed).remaining).toBe(1);
    vi.spyOn(cc.queue, 'flush').mockRejectedValue(new Error('trasig kö'));
    const seen = vi.fn();
    cc.onFlushed(seen);
    await expect(cc.flushAll()).rejects.toThrow('trasig kö');
    expect(cc.achievements.pending()).toEqual([]);
    expect(seen).not.toHaveBeenCalled();
  });

  it('en lyssnare som kastar stoppar inte de andra', async () => {
    const { createComputercat } = await import('./index');
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: fakeFetch({}).fetch, storage: memoryStorage() });
    const second = vi.fn();
    cc.onFlushed(() => {
      throw new Error('trasig lyssnare');
    });
    cc.onFlushed(second);
    await cc.flushAll();
    expect(second).toHaveBeenCalledTimes(1);
    expect(errors).toHaveBeenCalled();
  });
});
