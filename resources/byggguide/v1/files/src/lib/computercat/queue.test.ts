import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, NetworkError } from './http';
import { createScoreQueue, type QueueDeps } from './queue';
import { memoryStorage } from './storage';

const GAME = 'kattsvep';
const ok: QueueDeps['submit'] = () => Promise.resolve({});
const rejected422 = () =>
  Promise.reject(new ApiError(422, 'Score exceeds maximum allowed value.', {}, 'POST', '/games/kattsvep/leaderboards/daily-clicks'));
const offline = () => Promise.reject(new NetworkError('POST', '/games/kattsvep/leaderboards/daily-clicks', new TypeError('Failed to fetch')));

afterEach(() => vi.restoreAllMocks());

describe('createScoreQueue', () => {
  it('kön överlever en omladdning och töms en gång', async () => {
    const storage = memoryStorage();
    const first = createScoreQueue({ storage, game: GAME, submit: offline });
    first.enqueue('daily-clicks', 31, { round_ms: 10000 });
    expect((await first.flush()).remaining).toBe(1);

    const submit = vi.fn(ok);
    const second = createScoreQueue({ storage, game: GAME, submit });
    expect(second.pending()).toHaveLength(1);
    expect(await second.flush()).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(submit).toHaveBeenCalledWith('daily-clicks', 31, { round_ms: 10000 });
    expect(JSON.parse(storage.getItem(`computercat:${GAME}:score-queue`)!)).toEqual([]);
  });

  it('två samtidiga flush skickar varje resultat en gång', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const submit = vi.fn(async () => {
      await gate;
      return {};
    });
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 5);
    q.enqueue('daily-clicks', 6);
    const both = Promise.all([q.flush(), q.flush()]);
    release();
    const [a, b] = await both;
    expect(submit).toHaveBeenCalledTimes(2);
    expect(a.sent + b.sent).toBe(2);
    expect(q.pending()).toEqual([]);
  });

  it('två flikar mot samma lagring tappar inget och skickar inget två gånger', async () => {
    const storage = memoryStorage();
    const submitA = vi.fn(ok);
    const submitB = vi.fn(ok);
    const a = createScoreQueue({ storage, game: GAME, submit: submitA });
    const b = createScoreQueue({ storage, game: GAME, submit: submitB });

    a.enqueue('daily-clicks', 1);
    b.enqueue('daily-clicks', 2);
    a.enqueue('daily-clicks', 3);
    b.enqueue('daily-clicks', 4);
    expect(a.pending().map((i) => i.score)).toEqual([1, 2, 3, 4]);
    expect(b.pending().map((i) => i.score)).toEqual([1, 2, 3, 4]);

    expect(await a.flush()).toEqual({ sent: 4, dropped: [], remaining: 0 });
    expect(await b.flush()).toEqual({ sent: 0, dropped: [], remaining: 0 });
    expect(submitA.mock.calls.map((c) => c[1])).toEqual([1, 2, 3, 4]);
    expect(submitB).not.toHaveBeenCalled();
    expect(JSON.parse(storage.getItem(`computercat:${GAME}:score-queue`)!)).toEqual([]);
  });

  it('resultat från en annan flik under pågående flush finns kvar och skickas en gång', async () => {
    const storage = memoryStorage();
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const submitA = vi.fn(async () => {
      await gate;
      return {};
    });
    const submitB = vi.fn(ok);
    const a = createScoreQueue({ storage, game: GAME, submit: submitA });
    const b = createScoreQueue({ storage, game: GAME, submit: submitB });

    a.enqueue('daily-clicks', 1);
    const flushing = a.flush();
    await vi.waitFor(() => expect(submitA).toHaveBeenCalledTimes(1)); // A väntar på nätet med resultat 1
    b.enqueue('daily-clicks', 2); // flik B lägger till under tiden
    release();

    expect(await flushing).toEqual({ sent: 1, dropped: [], remaining: 1 });
    expect(a.pending().map((i) => i.score)).toEqual([2]); // A:s borttagning skrev inte över B:s post
    expect(await b.flush()).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(submitA).toHaveBeenCalledTimes(1);
    expect(submitB).toHaveBeenCalledTimes(1);
    expect(submitB.mock.calls[0][1]).toBe(2);
  });

  it('422 kastas synligt och blockerar inte kön', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const submit = vi.fn().mockImplementationOnce(rejected422).mockImplementationOnce(ok);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 999999);
    q.enqueue('daily-clicks', 12);
    const r = await q.flush();
    expect(r.sent).toBe(1);
    expect(r.remaining).toBe(0);
    expect(r.dropped).toHaveLength(1);
    expect(r.dropped[0].reason).toBe('422 Score exceeds maximum allowed value.');
    expect(error).toHaveBeenCalledWith(expect.stringContaining('999999'));
  });

  it('nätverksfel stoppar och behåller ordningen', async () => {
    const submit = vi.fn(offline);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 1);
    q.enqueue('daily-clicks', 2);
    const r = await q.flush();
    expect(r).toEqual({ sent: 0, dropped: [], remaining: 2 });
    expect(submit).toHaveBeenCalledTimes(1);
    expect(q.pending().map((i) => i.score)).toEqual([1, 2]);
  });

  it('401 och 429 behålls för nästa försök', async () => {
    const submit = vi
      .fn()
      .mockImplementationOnce(() => Promise.reject(new ApiError(401, 'Unauthenticated.', {}, 'POST', '/x')))
      .mockImplementationOnce(() => Promise.reject(new ApiError(429, 'Too Many Attempts.', {}, 'POST', '/x')));
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 1);
    expect((await q.flush()).remaining).toBe(1);
    expect((await q.flush()).remaining).toBe(1);
  });

  it('för gamla resultat kastas synligt', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    let t = Date.parse('2026-10-13T08:00:00Z');
    const submit = vi.fn(ok);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit, now: () => t });
    q.enqueue('daily-clicks', 3);
    t += 25 * 60 * 60 * 1000;
    const r = await q.flush();
    expect(r.dropped.map((d) => d.reason)).toEqual(['äldre än 24 h']);
    expect(submit).not.toHaveBeenCalled();
    expect(warn).toHaveBeenCalled();
  });

  it('enqueue kastar om lagringen inte går att skriva, så resultatet inte försvinner tyst', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const storage = memoryStorage();
    storage.setItem = () => {
      throw new DOMException('full', 'QuotaExceededError');
    };
    const q = createScoreQueue({ storage, game: GAME, submit: ok });
    expect(() => q.enqueue('daily-clicks', 5)).toThrow('Kunde inte spara resultatet lokalt');
  });

  it('en trasig post tas bort ensam, resten av kön finns kvar och en varning visas', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const storage = memoryStorage();
    const good = { id: 'a', type: 'daily-clicks', score: 7, metadata: null, queuedAt: Date.now() };
    storage.setItem(
      `computercat:${GAME}:score-queue`,
      JSON.stringify([good, { id: 'b', type: 'daily-clicks', score: 'sju', queuedAt: 1 }, 'skräp', null]),
    );
    const submit = vi.fn(ok);
    const q = createScoreQueue({ storage, game: GAME, submit });
    expect(q.pending()).toEqual([good]);
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('3'));
    expect(await q.flush()).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(submit).toHaveBeenCalledTimes(1);
  });

  it('oväntat fel (inte ApiError/NetworkError) blockerar inte köhuvudet: posten kastas synligt', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const submit = vi
      .fn()
      .mockImplementationOnce(() => Promise.reject(new Error('Oväntat svar från POST /x')))
      .mockImplementationOnce(ok);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 1);
    q.enqueue('daily-clicks', 2);
    const r = await q.flush();
    expect(r.sent).toBe(1);
    expect(r.remaining).toBe(0);
    expect(r.dropped.map((d) => d.reason)).toEqual(['oväntat fel: Oväntat svar från POST /x']);
    expect(error).toHaveBeenCalled();
  });
});
