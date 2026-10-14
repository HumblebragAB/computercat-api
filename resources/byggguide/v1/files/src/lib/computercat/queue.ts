import { ApiError, NetworkError, isRecord } from './http';
import { assertScore } from './leaderboards';
import { readJson, writeJson, type KeyValueStore } from './storage';

export type QueuedScore = {
  id: string;
  type: string;
  score: number;
  metadata: Record<string, unknown> | null;
  queuedAt: number;
};
export type DroppedScore = { item: QueuedScore; reason: string };
export type FlushResult = { sent: number; dropped: DroppedScore[]; remaining: number };

/**
 * Servern lägger resultatet i den period som gäller när det tas emot (UTC), inte när
 * det spelades. Ett dygn gammalt resultat skulle hamna på fel dags lista, så det kastas.
 */
export const DEFAULT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

export type QueueDeps = {
  storage: KeyValueStore;
  game: string;
  submit: (type: string, score: number, metadata: Record<string, unknown> | undefined) => Promise<unknown>;
  now?: () => number;
  maxAgeMs?: number;
};

function isQueuedScore(i: unknown): i is QueuedScore {
  return (
    isRecord(i) &&
    typeof i.id === 'string' &&
    typeof i.type === 'string' &&
    typeof i.score === 'number' &&
    typeof i.queuedAt === 'number' &&
    (i.metadata === null || isRecord(i.metadata))
  );
}

const isArray = (v: unknown): v is unknown[] => Array.isArray(v);

/**
 * Vad ett fel från submit betyder för just den posten. Tillfälligt (offline, 5xx, 401, 408, 429): stanna
 * och försök senare, i samma ordning. Annars (4xx som gäller resultatet, eller ett oväntat fel som
 * inte är ApiError/NetworkError, t.ex. fel svarsform): posten kastas synligt. Den flyttas inte åt sidan,
 * eftersom ett fel som aldrig går över annars skulle blockera hela kön tills posten blir 24 h gammal.
 */
function dropReason(e: unknown): string | null {
  if (e instanceof NetworkError) return null;
  if (e instanceof ApiError) {
    return e.status >= 400 && e.status < 500 && ![401, 408, 429].includes(e.status) ? `${e.status} ${e.message}` : null;
  }
  return `oväntat fel: ${e instanceof Error ? e.message : String(e)}`;
}

export function createScoreQueue(deps: QueueDeps) {
  const key = `computercat:${deps.game}:score-queue`;
  const now = deps.now ?? Date.now;
  const maxAge = deps.maxAgeMs ?? DEFAULT_MAX_AGE_MS;
  let seq = 0;
  let chain: Promise<unknown> = Promise.resolve();

  // Lagringen delas av alla flikar med samma spel. Inget hålls i minnet: varje
  // läsning går mot lagringen, och varje ändring läser, ändrar och skriver tillbaka
  // i ett enda synkront steg (inget await emellan), så en annan flik kan inte
  // komma emellan i den här fliken och ingen ändring skriver över en annans.
  // En enskild trasig post tas bort ensam (med varning); resten av kön behålls.
  const load = (): QueuedScore[] => {
    const raw = readJson(deps.storage, key, isArray) ?? [];
    const valid = raw.filter(isQueuedScore);
    if (valid.length < raw.length) {
      console.warn(`[computercat] ${raw.length - valid.length} ogiltiga poster i resultatkön togs bort`);
      writeJson(deps.storage, key, valid);
    }
    return valid;
  };
  /** Returnerar om ändringen gick att spara. */
  const change = (fn: (current: QueuedScore[]) => QueuedScore[]): boolean =>
    writeJson(deps.storage, key, fn(load()));
  const remove = (id: string) => change((current) => current.filter((i) => i.id !== id));

  function enqueue(type: string, score: number, metadata: Record<string, unknown> | null = null): QueuedScore {
    assertScore(score);
    const t = now();
    const item: QueuedScore = {
      id: `${t.toString(36)}-${(seq++).toString(36)}-${Math.random().toString(36).slice(2, 8)}`,
      type,
      score,
      metadata,
      queuedAt: t,
    };
    // Tyst förlust är värst: går det inte att spara kastar vi, så att spelet kan visa att resultatet inte finns kvar.
    if (!change((current) => [...current, item])) {
      throw new Error('Kunde inte spara resultatet lokalt (lagringen är full eller blockerad); det skickades inte.');
    }
    return item;
  }

  async function run(): Promise<FlushResult> {
    const result: FlushResult = { sent: 0, dropped: [], remaining: 0 };
    for (const item of load()) {
      // En annan flik kan ha skickat eller kastat posten sedan vi började.
      if (!load().some((i) => i.id === item.id)) continue;
      if (now() - item.queuedAt > maxAge) {
        remove(item.id);
        result.dropped.push({ item, reason: 'äldre än 24 h' });
        console.warn(`[computercat] resultat ${item.score} till ${item.type} var för gammalt och skickades inte`);
        continue;
      }
      try {
        await deps.submit(item.type, item.score, item.metadata ?? undefined);
        remove(item.id);
        result.sent++;
      } catch (e) {
        const reason = dropReason(e);
        if (reason === null) break; // tillfälligt fel: försök senare, i samma ordning
        remove(item.id);
        result.dropped.push({ item, reason });
        console.error(`[computercat] resultat ${item.score} till ${item.type} avvisades: ${reason}`);
      }
    }
    result.remaining = load().length;
    return result;
  }

  /** Körningar läggs på kö efter varandra, så samma resultat skickas aldrig två gånger parallellt i en flik. */
  function flush(): Promise<FlushResult> {
    const p = chain.then(run);
    chain = p.catch(() => undefined);
    return p;
  }

  return { enqueue, flush, pending: () => load() };
}
