import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { ACHIEVEMENT_BATCH, ACHIEVEMENT_SLUG, createAchievements, MAX_ACHIEVEMENT_SLUG } from './achievements';
import { createAuth, type Authed } from './auth';
import { memoryStorage, type KeyValueStore } from './storage';

const GAME = 'kattsvep';
const KEY = `computercat:${GAME}:achievement-queue`;
const PATH = '/games/kattsvep/achievements';
const unlocked = (slug: string) => ({ slug, name: slug, description: 'd', icon: '*', unlocked_at: '2026-10-16T10:00:00.000000Z' });
const answer = (body: Record<string, unknown>) => json(201, { unlocked: [], already_unlocked: 0, unknown: [], rejected: [], ...body });

function setup(routes: Parameters<typeof fakeFetch>[0], storage: KeyValueStore = storedSession(GAME)) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
  return { f, storage, ach: createAchievements({ storage, game: GAME, authed: auth.authed }) };
}

const bodies = (f: ReturnType<typeof fakeFetch>) =>
  f.calls.filter((c) => c.method === 'POST').map((c) => (c.body as { slugs: string[] }).slugs);

beforeEach(() => {
  vi.spyOn(console, 'warn').mockImplementation(() => {});
  vi.spyOn(console, 'error').mockImplementation(() => {});
});
afterEach(() => vi.restoreAllMocks());

describe('createAchievements: läsning', () => {
  it('list ger definitionerna, också hemliga med ???', async () => {
    const defs = [
      { id: 1, slug: 'first-log', name: 'Första stocken', description: 'Hugg ett träd', icon: 'stock', is_secret: false, sort_order: 1 },
      { id: 2, slug: 'first-storm', name: 'Stormen', description: '???', icon: 'storm', is_secret: true, sort_order: 2 },
    ];
    const { f, ach } = setup({ [`GET ${PATH}`]: [json(200, { data: defs })] });
    expect(await ach.list()).toEqual(defs);
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|stored');
    expect(f.unmatched).toEqual([]);
  });

  it('mine ger spelarens upplåsta', async () => {
    const { ach } = setup({ [`GET ${PATH}/me`]: [json(200, { data: [unlocked('first-log')] })] });
    expect(await ach.mine()).toEqual([unlocked('first-log')]);
  });

  it('list och mine kastar vid oväntad svarsform', async () => {
    const { ach } = setup({
      [`GET ${PATH}`]: [json(200, { data: [{ id: 1 }] })],
      [`GET ${PATH}/me`]: [json(200, { items: [] })],
    });
    await expect(ach.list()).rejects.toThrow(`Oväntat svar från GET ${PATH}`);
    await expect(ach.mine()).rejects.toThrow(`Oväntat svar från GET ${PATH}/me`);
  });
  it('ett list-element där is_secret saknas ger fel', async () => {
    const def = { id: 1, slug: 'a', name: 'A', description: 'd', icon: 'i', sort_order: 1 };
    const { ach } = setup({ [`GET ${PATH}`]: [json(200, { data: [def] })] });
    await expect(ach.list()).rejects.toThrow(`Oväntat svar från GET ${PATH}`);
  });

  it('ett mine-element med fel typ på unlocked_at ger fel', async () => {
    const { ach } = setup({ [`GET ${PATH}/me`]: [json(200, { data: [{ ...unlocked('a'), unlocked_at: 5 }] })] });
    await expect(ach.mine()).rejects.toThrow(`Oväntat svar från GET ${PATH}/me`);
  });
});

describe('createAchievements: report', () => {
  it('kastar RangeError synkront för en ogiltig slug och köar ingenting', () => {
    const { f, ach, storage } = setup({});
    for (const bad of ['', '   ', 'x'.repeat(MAX_ACHIEVEMENT_SLUG + 1), 42 as unknown as string, 'First-Log', 'first log', 'första', '-first', 'first\n']) {
      expect(() => ach.report(['first-log', bad])).toThrow(RangeError);
    }
    expect(storage.getItem(KEY)).toBeNull();
    expect(f.calls).toEqual([]);
  });

  it('slugmönstret är serverns (SlugRule::PATTERN) och tillåter siffror, _, . och -', () => {
    expect(ACHIEVEMENT_SLUG.source).toBe('^[a-z0-9][a-z0-9_.-]*$');
    const { ach } = setup({ [`POST ${PATH}`]: ['network-error'] });
    expect(ach.report(['first_12x12', 'solve.5', 'belt-network', '9lives']).queued).toHaveLength(4);
  });

  it('en slug på exakt 50 tecken går igenom', async () => {
    const slug = 'x'.repeat(MAX_ACHIEVEMENT_SLUG);
    const { ach } = setup({ [`POST ${PATH}`]: [answer({ unlocked: [unlocked(slug)] })] });
    expect((await ach.report([slug]).flushed).unlocked.map((u) => u.slug)).toEqual([slug]);
  });

  it('offline: köas lokalt utan dubbletter, ligger kvar och skickas senare i en omgång', async () => {
    const { f, ach, storage } = setup({
      [`POST ${PATH}`]: ['network-error', 'network-error', answer({ unlocked: [unlocked('a'), unlocked('b')] })],
    });
    const first = ach.report(['a', 'a']);
    expect(first.queued).toEqual(['a']);
    expect(await first.flushed).toMatchObject({ sent: 0, dropped: [], remaining: 1 });

    const second = ach.report(['a', 'b']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual(['a', 'b']);
    expect(await second.flushed).toMatchObject({ sent: 0, remaining: 2 });

    const r = await ach.flush();
    expect(r).toMatchObject({ sent: 2, remaining: 0, dropped: [], unknown: [], rejected: [] });
    expect(r.unlocked.map((u) => u.slug)).toEqual(['a', 'b']);
    expect(bodies(f).at(-1)).toEqual(['a', 'b']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it('skickar i omgångar om högst 10', async () => {
    expect(ACHIEVEMENT_BATCH).toBe(10);
    const slugs = Array.from({ length: 23 }, (_, i) => `a${i}`);
    const { f, ach } = setup({
      [`POST ${PATH}`]: [(req) => answer({ unlocked: (req.body as { slugs: string[] }).slugs.map(unlocked) })],
    });
    const r = await ach.report(slugs).flushed;
    expect(bodies(f).map((b) => b.length)).toEqual([10, 10, 3]);
    expect(r).toMatchObject({ sent: 23, remaining: 0, dropped: [] });
    expect(r.unlocked.map((u) => u.slug)).toEqual(slugs);
  });

  it('unknown och rejected syns och tas ur kön', async () => {
    const { ach, storage } = setup({
      [`POST ${PATH}`]: [answer({ unlocked: [unlocked('a')], already_unlocked: 1, unknown: ['typo'], rejected: ['c'] })],
    });
    const r = await ach.report(['a', 'b', 'typo', 'c']).flushed;
    expect(r).toMatchObject({ sent: 4, unknown: ['typo'], rejected: ['c'], dropped: [], remaining: 0 });
    expect(r.unlocked.map((u) => u.slug)).toEqual(['a']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it('ett svar utan unknown och rejected (äldre server) räknas som skickat', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(201, { unlocked: [], already_unlocked: 1 })] });
    expect(await ach.report(['a']).flushed).toEqual({ sent: 1, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 });
  });

  it('422 tar bort omgången synligt i stället för att blockera kön', async () => {
    const { ach, storage } = setup({ [`POST ${PATH}`]: [json(422, { message: 'The slugs.0 field is invalid.' })] });
    const r = await ach.report(['a', 'b']).flushed;
    expect(r.dropped).toEqual([
      { slug: 'a', reason: '422 The slugs.0 field is invalid.' },
      { slug: 'b', reason: '422 The slugs.0 field is invalid.' },
    ]);
    expect(r.remaining).toBe(0);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it.each([503, 408, 429])('%i väntar och skickas vid nästa tömning', async (status) => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(status, { message: 'x' }), answer({ unlocked: [unlocked('a')] })] });
    expect(await ach.report(['a']).flushed).toMatchObject({ sent: 0, dropped: [], remaining: 1 });
    expect(await ach.flush()).toMatchObject({ sent: 1, remaining: 0 });
  });

  it('en oväntad svarsform tar bort omgången med skäl', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(201, { ok: true })] });
    const r = await ach.report(['a']).flushed;
    expect(r.dropped).toHaveLength(1);
    expect(r.dropped[0].reason).toMatch(/^oväntat fel: Oväntat svar från POST /);
  });

  it('full lagring: report kastar och inget skickas', () => {
    const inner = storedSession(GAME);
    const full: KeyValueStore = {
      getItem: (k) => inner.getItem(k),
      setItem: (k, v) => {
        if (k === KEY) throw new DOMException('full', 'QuotaExceededError');
        inner.setItem(k, v);
      },
      removeItem: (k) => inner.removeItem(k),
    };
    const { f, ach } = setup({}, full);
    expect(() => ach.report(['a'])).toThrow('Kunde inte spara upplåsningen lokalt');
    expect(f.calls).toEqual([]);
  });

  it('en omgång som inte går att ta bort ur kön skickas inte om och om igen', async () => {
    const inner = storedSession(GAME);
    let writes = 0;
    const stuck: KeyValueStore = {
      getItem: (k) => inner.getItem(k),
      setItem: (k, v) => {
        if (k === KEY && ++writes > 1) throw new Error('blockerad');
        inner.setItem(k, v);
      },
      removeItem: (k) => inner.removeItem(k),
    };
    const { f, ach } = setup({ [`POST ${PATH}`]: [answer({ already_unlocked: 1 })] }, stuck);
    const r = await ach.report(['a']).flushed;
    expect(bodies(f)).toEqual([['a']]);
    expect(r).toMatchObject({ sent: 1, remaining: 1 });
  });

  it('två samtidiga tömningar skickar varje omgång en gång', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const authed = vi.fn(async () => {
      await gate;
      return { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] };
    }) as unknown as Authed;
    const ach = createAchievements({ storage: memoryStorage(), game: GAME, authed });
    const a = ach.report(['a']).flushed;
    const b = ach.flush();
    release();
    const [ra, rb] = await Promise.all([a, b]);
    expect(authed).toHaveBeenCalledTimes(1);
    expect(ra.sent + rb.sent).toBe(1);
    expect(ach.pending()).toEqual([]);
  });

  it('en körning skickar bara det som låg i kön när den startade, så resultatet når rätt anropare', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const sent: string[][] = [];
    const authed = vi.fn(async (_m: string, _p: string, body: { slugs: string[] }) => {
      sent.push(body.slugs);
      if (sent.length === 1) await gate;
      return { unlocked: body.slugs.map(unlocked), already_unlocked: 0, unknown: [], rejected: [] };
    }) as unknown as Authed;
    const ach = createAchievements({ storage: memoryStorage(), game: GAME, authed });
    const first = ach.report(['a']).flushed;
    await vi.waitFor(() => expect(authed).toHaveBeenCalledTimes(1));
    const second = ach.report(['b']).flushed;
    release();
    const [ra, rb] = await Promise.all([first, second]);
    expect(sent).toEqual([['a'], ['b']]);
    expect(ra.unlocked.map((u) => u.slug)).toEqual(['a']);
    expect(ra.sent).toBe(1);
    expect(rb.unlocked.map((u) => u.slug)).toEqual(['b']);
    expect(rb.sent).toBe(1);
  });

  it('ett fel element i unlocked tar bort omgången med skäl', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [answer({ unlocked: [unlocked('a'), { slug: 'b' }] })] });
    const r = await ach.report(['a', 'b']).flushed;
    expect(r.unlocked).toEqual([]);
    expect(r.dropped.map((d) => d.slug)).toEqual(['a', 'b']);
    expect(r.dropped[0].reason).toMatch(/^oväntat fel: Oväntat svar från POST /);
  });

  it('en icke-sträng i unknown eller rejected tar bort omgången med skäl', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [answer({ unknown: ['x', 7] }), answer({ rejected: 'a' })] });
    expect((await ach.report(['a']).flushed).dropped).toHaveLength(1);
    expect((await ach.report(['b']).flushed).dropped).toHaveLength(1);
  });

  it('en tom kö gör inga anrop', async () => {
    const { f, ach } = setup({});
    expect(await ach.flush()).toEqual({ sent: 0, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 });
    expect(f.calls).toEqual([]);
  });

  it('en trasig post i lagringen tas bort ensam', async () => {
    const storage = storedSession(GAME);
    storage.setItem(KEY, JSON.stringify(['a', 7, '', 'b']));
    const { ach } = setup({}, storage);
    expect(ach.pending()).toEqual(['a', 'b']);
  });
});
