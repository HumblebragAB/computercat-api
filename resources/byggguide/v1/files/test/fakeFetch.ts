import type { FetchLike } from '@/lib/computercat/http';
import { memoryStorage, type KeyValueStore } from '@/lib/computercat/storage';

export const BASE = 'https://api.test/api/v1';

export type FakeRequest = {
  method: string;
  path: string;
  headers: Record<string, string>;
  body: unknown;
};

type Outcome = Response | 'network-error';
export type Reply = Outcome | ((req: FakeRequest) => Outcome);

export function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

/**
 * Falsk fetch. Nyckeln är "METOD /sökväg" relativt BASE. Svaren förbrukas i
 * ordning; det sista upprepas. Ett anrop utan svar hamnar i `unmatched` och
 * kastar som ett nätverksfel, så testet ska alltid kontrollera `unmatched`.
 */
export function fakeFetch(routes: Record<string, Reply[]>) {
  const calls: FakeRequest[] = [];
  const unmatched: string[] = [];
  const queues = new Map(Object.entries(routes).map(([k, v]) => [k, [...v]]));

  const fetch: FetchLike = async (input, init) => {
    if (!input.startsWith(BASE)) throw new Error(`fakeFetch: oväntad bas-URL ${input}`);
    const method = (init.method ?? 'GET').toUpperCase();
    const path = input.slice(BASE.length);
    const headers = (init.headers ?? {}) as Record<string, string>;
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined;
    const req: FakeRequest = { method, path, headers, body };
    calls.push(req);

    const key = `${method} ${path}`;
    const queue = queues.get(key);
    if (!queue || queue.length === 0) {
      unmatched.push(key);
      throw new TypeError(`fakeFetch: inget svar för ${key}`);
    }
    const reply = queue.length > 1 ? queue.shift()! : queue[0];
    const outcome = typeof reply === 'function' ? reply(req) : reply;
    if (outcome === 'network-error') throw new TypeError('Failed to fetch');
    return outcome.clone();
  };

  return { fetch, calls, unmatched };
}

export function storedSession(game: string, token = '1|stored', issuedAt?: number): KeyValueStore {
  const storage = memoryStorage();
  storage.setItem(
    `computercat:${game}:session`,
    JSON.stringify({ token, userId: 7, displayName: 'Player-7', ...(issuedAt === undefined ? {} : { issuedAt }) }),
  );
  return storage;
}
