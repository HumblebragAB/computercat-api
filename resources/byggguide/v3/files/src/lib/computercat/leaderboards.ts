import type { Authed } from './auth';
import { isRecord } from './http';

export type LeaderboardEntry = {
  rank: number | null;
  score: number;
  metadata: Record<string, unknown> | null;
  submitted_at: string;
  user: { id: number; display_name: string };
};
export type LeaderboardPage = { entries: LeaderboardEntry[]; periodKey: string };
export type MyRank = { entry: LeaderboardEntry | null; rank: number | null; periodKey: string };

const seg = encodeURIComponent;

/** LeaderboardController::store kräver integer|min:0 (LeaderboardController.php:59-62). */
export function assertScore(score: number): void {
  if (!Number.isSafeInteger(score) || score < 0) {
    throw new RangeError(`score måste vara ett heltal ≥ 0, fick ${score}`);
  }
}

export function createLeaderboards(game: string, authed: Authed) {
  const base = `/games/${seg(game)}/leaderboards`;

  return {
    /** Utan periodKey: serverns aktuella period (UTC). Periodnyckeln räknas aldrig ut i klienten. */
    async get(type: string, periodKey?: string): Promise<LeaderboardPage> {
      const path = periodKey ? `${base}/${seg(type)}/${seg(periodKey)}` : `${base}/${seg(type)}`;
      const res = await authed<unknown>('GET', path);
      if (!isRecord(res) || !Array.isArray(res.data) || typeof res.period_key !== 'string') {
        throw new Error(`Oväntat svar från GET ${path}`);
      }
      return { entries: res.data as LeaderboardEntry[], periodKey: res.period_key };
    },

    async me(type: string): Promise<MyRank> {
      const path = `${base}/${seg(type)}/me`;
      const res = await authed<unknown>('GET', path);
      if (!isRecord(res) || typeof res.period_key !== 'string') throw new Error(`Oväntat svar från GET ${path}`);
      return {
        entry: (res.data as LeaderboardEntry | null) ?? null,
        rank: typeof res.rank === 'number' ? res.rank : null,
        periodKey: res.period_key,
      };
    },

    /** Servern behåller bästa resultat per spelare och period, så en dubblett är ofarlig. */
    async submit(type: string, score: number, metadata?: Record<string, unknown>): Promise<LeaderboardEntry> {
      assertScore(score);
      const path = `${base}/${seg(type)}`;
      const res = await authed<unknown>('POST', path, metadata ? { score, metadata } : { score });
      if (!isRecord(res) || !isRecord(res.data)) throw new Error(`Oväntat svar från POST ${path}`);
      return res.data as LeaderboardEntry;
    },
  };
}
