import { createAuth } from './auth';
import { readConfig, type ComputercatConfig } from './config';
import type { FetchLike } from './http';
import { createLeaderboards } from './leaderboards';
import { createScoreQueue } from './queue';
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

  return {
    config: opts.config,
    auth,
    leaderboards,
    saves,
    queue,
    /** Lokalt först: köa resultatet och försök skicka direkt. `flushed` säger vad som hände. */
    recordScore(type: string, score: number, metadata?: Record<string, unknown>) {
      const item = queue.enqueue(type, score, metadata ?? null);
      return { item, flushed: queue.flush() };
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
