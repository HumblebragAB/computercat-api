import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError } from './http';
import { createSaves } from './saves';

const GAME = 'kattsvep';
const PATH = '/games/kattsvep/saves/main';
type Data = { best: number };
const save = (version: number, best: number) => ({
  save_key: 'main',
  data: { best },
  version,
  checksum: null,
  saved_at: '2026-10-13T09:00:00Z',
});
const conflict = (version: number, best: number) =>
  json(409, { message: 'Version conflict. Fetch latest save and merge.', server_version: version, data: save(version, best) });
const merge = (a: Data, b: Data): Data => ({ best: Math.max(a.best, b.best) });

function setup(routes: Parameters<typeof fakeFetch>[0]) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
  return { f, saves: createSaves(GAME, auth.authed) };
}

afterEach(() => vi.restoreAllMocks());

describe('createSaves', () => {
  it('saknad sparning ger null', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { saves } = setup({ [`GET ${PATH}`]: [json(404, { message: 'Save not found.' })] });
    expect(await saves.get('main')).toBeNull();
  });

  it('okänt spel är ett fel, inte en tom sparning', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { saves } = setup({ [`GET ${PATH}`]: [json(404, { message: 'Game not found.' })] });
    await expect(saves.get('main')).rejects.toBeInstanceOf(ApiError);
  });

  it('put skickar data och version och returnerar ny version', async () => {
    const { f, saves } = setup({ [`PUT ${PATH}`]: [json(200, { data: save(4, 9) })] });
    expect(await saves.put('main', { best: 9 }, 3)).toEqual({ ok: true, save: save(4, 9) });
    expect(f.calls[0].body).toEqual({ data: { best: 9 }, version: 3 });
  });

  it('409 ger serverns sparning som konflikt', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { saves } = setup({ [`PUT ${PATH}`]: [conflict(5, 12)] });
    expect(await saves.put('main', { best: 9 }, 3)).toEqual({ ok: false, conflict: save(5, 12) });
  });

  it('tomt data-objekt avvisas innan anropet (servern kräver required|array)', async () => {
    const { f, saves } = setup({});
    await expect(saves.put('main', {}, 0)).rejects.toBeInstanceOf(RangeError);
    expect(f.calls).toHaveLength(0);
  });

  it('sync utan molnsparning skickar lokalt med version 0', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(404, { message: 'Save not found.' })],
      [`PUT ${PATH}`]: [json(200, { data: save(1, 4) })],
    });
    expect((await saves.sync<Data>('main', { best: 4 }, merge)).version).toBe(1);
    expect(f.calls[1].body).toEqual({ data: { best: 4 }, version: 0 });
  });

  it('sync slår ihop med molnet och skickar med molnets version', async () => {
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [json(200, { data: save(4, 10) })],
    });
    await saves.sync<Data>('main', { best: 4 }, merge);
    expect(f.calls[1].body).toEqual({ data: { best: 10 }, version: 3 });
  });

  it('sync vid 409 slår ihop med serverns kopia och försöker en gång till', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [conflict(4, 15), json(200, { data: save(5, 15) })],
    });
    expect((await saves.sync<Data>('main', { best: 4 }, merge)).version).toBe(5);
    expect(f.calls[2].body).toEqual({ data: { best: 15 }, version: 4 });
  });

  it('sync som krockar två gånger ger fel', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [conflict(4, 15), conflict(5, 16)],
    });
    await expect(saves.sync<Data>('main', { best: 4 }, merge)).rejects.toThrow('krockade två gånger');
  });
});
