import { createAuth } from './auth';
import { readConfig, type ComputercatConfig } from './config';
import type { FetchLike } from './http';
import { createLeaderboards } from './leaderboards';
import { createMatches } from './matches';
import { createAchievements, type AchievementFlushResult } from './achievements';
import { createScoreQueue, type FlushResult } from './queue';
import { createSaves } from './saves';
import { browserStorage, type KeyValueStore } from './storage';

export { readConfig, parseConfig, DEFAULT_BASE_URL, type ComputercatConfig } from './config';
export { ApiError, NetworkError } from './http';
export { describeError } from './errors';
export { startBackgroundSync } from './background';
export { createProof, fnv1aSignature, type PlayProof } from './proof';
export type { Session } from './auth';
export type { LeaderboardEntry, LeaderboardPage, MyRank } from './leaderboards';
export type { QueuedScore, FlushResult } from './queue';
export type { CloudSave, PutResult } from './saves';

export { assertAchievementSlug, MAX_ACHIEVEMENT_SLUG, ACHIEVEMENT_BATCH, ACHIEVEMENT_SLUG } from './achievements';
export type { AchievementDefinition, UnlockedAchievement, DroppedAchievement, AchievementFlushResult } from './achievements';

export { MatchError, inviteUrl, readJoinToken } from './matches';
export type { Matches, MatchSnapshot, MatchStatus, MatchPlayer, MatchEvent, ActiveMatch, Seat, WatchError, WatchHandlers, WatchOptions } from './matches';

/** Vad en tömning av båda köerna gav (BackgroundSync, onFlushed). */
export type FlushAllResult = { scores: FlushResult; achievements: AchievementFlushResult };

export type ComputercatOptions = {
  config: ComputercatConfig;
  fetch?: FetchLike;
  storage?: KeyValueStore;
  now?: () => number;
};

export function createComputercat(opts: ComputercatOptions) {
  const { game, baseUrl } = opts.config;
  // Slå upp globalThis.fetch vid varje anrop: en lagrad referens till window.fetch ger "Illegal invocation".
  const fetchImpl: FetchLike = opts.fetch ?? ((input, init) => globalThis.fetch(input, init));
  const storage = opts.storage ?? browserStorage();

  const auth = createAuth({ fetch: fetchImpl, baseUrl, game, storage, now: opts.now });
  const leaderboards = createLeaderboards(game, auth.authed);
  const saves = createSaves(game, auth.authed);
  const queue = createScoreQueue({ storage, game, submit: leaderboards.submit, now: opts.now });
  const achievements = createAchievements({ storage, game, authed: auth.authed });
  const matches = createMatches(game, { authed: auth.authed, authedMeta: auth.authedMeta });
  const listeners = new Set<(r: FlushAllResult) => void>();

  /**
   * Resultatkön först, sedan achievementkön, var och en i eget try: kastar den ena töms den andra ändå.
   * Kastade någon avvisas flushAll efteråt med det första felet; lyssnarna får bara hela resultat.
   */
  async function flushAll(): Promise<FlushAllResult> {
    const failures: unknown[] = [];
    let scores: FlushResult | null = null;
    let achieved: AchievementFlushResult | null = null;
    try {
      scores = await queue.flush();
    } catch (e) {
      console.error('[computercat] resultatkön kunde inte tömmas', e);
      failures.push(e);
    }
    try {
      achieved = await achievements.flush();
    } catch (e) {
      console.error('[computercat] achievementkön kunde inte tömmas', e);
      failures.push(e);
    }
    if (failures.length > 0 || scores === null || achieved === null) throw failures[0];
    const result: FlushAllResult = { scores, achievements: achieved };
    for (const cb of [...listeners]) {
      try {
        cb(result);
      } catch (e) {
        console.error('[computercat] en onFlushed-lyssnare kastade', e);
      }
    }
    return result;
  }

  return {
    config: opts.config,
    auth,
    leaderboards,
    saves,
    queue,
    achievements,
    matches,
    /** Lokalt först: köa resultatet och försök skicka direkt. `flushed` säger vad som hände. */
    recordScore(type: string, score: number, metadata?: Record<string, unknown>) {
      const item = queue.enqueue(type, score, metadata ?? null);
      return { item, flushed: queue.flush() };
    },
    flushAll,
    /** Prenumerera på varje flushAll (också bakgrundens). Returnerar en funktion som avslutar prenumerationen. */
    onFlushed(cb: (r: FlushAllResult) => void): () => void {
      listeners.add(cb);
      return () => {
        listeners.delete(cb);
      };
    },
  };
}

export type Computercat = ReturnType<typeof createComputercat>;
export type ComputercatResult = { ok: true; client: Computercat } | { ok: false; error: string };

let instance: ComputercatResult | null = null;

/** Klienten för den här sidan. Bara i webbläsaren; statisk export kör ingen kod på servern. */
export function getComputercat(): ComputercatResult {
  if (typeof window === 'undefined') {
    return { ok: false, error: 'getComputercat() anropades utanför webbläsaren.' };
  }
  if (instance) return instance;
  const cfg = readConfig();
  if (!cfg.ok) console.error(`[computercat] ${cfg.error}`);
  instance = cfg.ok ? { ok: true, client: createComputercat({ config: cfg.config }) } : { ok: false, error: cfg.error };
  return instance;
}
