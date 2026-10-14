import { describe, expect, it } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { createLeaderboards } from './leaderboards';

const GAME = 'kattsvep';
const entry = {
  rank: 1,
  score: 31,
  metadata: null,
  submitted_at: '2026-10-13T09:00:00Z',
  user: { id: 7, display_name: 'Mia' },
};

function setup(routes: Parameters<typeof fakeFetch>[0]) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
  return { f, lb: createLeaderboards(GAME, auth.authed) };
}

describe('createLeaderboards', () => {
  it('hämtar aktuell period inloggad och tar period_key från servern', async () => {
    const { f, lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks': [json(200, { data: [entry], period_key: '2026-10-13' })],
    });
    const page = await lb.get('daily-clicks');
    expect(page).toEqual({ entries: [entry], periodKey: '2026-10-13' });
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|stored');
  });

  it('hämtar en given period med kodade sökvägssegment', async () => {
    const { f, lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks/2026-10-12': [json(200, { data: [], period_key: '2026-10-12' })],
    });
    expect((await lb.get('daily-clicks', '2026-10-12')).periodKey).toBe('2026-10-12');
    expect(f.unmatched).toEqual([]);
  });

  it('min placering är null när spelaren inte har något resultat', async () => {
    const { lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks/me': [json(200, { data: null, period_key: '2026-10-13' })],
    });
    expect(await lb.me('daily-clicks')).toEqual({ entry: null, rank: null, periodKey: '2026-10-13' });
  });

  it('skickar score och metadata och läser 201 {data}', async () => {
    const { f, lb } = setup({
      'POST /games/kattsvep/leaderboards/daily-clicks': [json(201, { data: entry })],
    });
    expect(await lb.submit('daily-clicks', 31, { round_ms: 10000 })).toEqual(entry);
    expect(f.calls[0].body).toEqual({ score: 31, metadata: { round_ms: 10000 } });
  });

  it('avvisar negativa och icke-heltal innan något skickas', async () => {
    const { f, lb } = setup({});
    await expect(lb.submit('daily-clicks', -1)).rejects.toBeInstanceOf(RangeError);
    await expect(lb.submit('daily-clicks', 1.5)).rejects.toBeInstanceOf(RangeError);
    expect(f.calls).toHaveLength(0);
  });
});
