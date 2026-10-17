export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH';
export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export const TIMEOUT_MS = 10_000;
const LOG_LIMIT = 4096;

export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  readonly method: HttpMethod;
  readonly path: string;
  /** Retry-After i sekunder (bara från requestJsonMeta, och bara i sekundform); annars null. */
  readonly retryAfter: number | null;

  constructor(status: number, message: string, body: unknown, method: HttpMethod, path: string, retryAfter: number | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
    this.method = method;
    this.path = path;
    this.retryAfter = retryAfter;
  }
}

export class NetworkError extends Error {
  readonly method: HttpMethod;
  readonly path: string;

  constructor(method: HttpMethod, path: string, cause: unknown) {
    super(`Kunde inte nå Computercat (${method} ${path})`, { cause });
    this.name = 'NetworkError';
    this.method = method;
    this.path = path;
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

const SENSITIVE_KEYS = new Set(['password', 'password_confirmation', 'current_password', 'token', 'secret']);

/** Maskerar värden vars nyckel är känslig (skiftlägesokänsligt), rekursivt. */
function redact(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(redact);
  if (isRecord(value)) {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [k, SENSITIVE_KEYS.has(k.toLowerCase()) ? '[REDACTED]' : redact(v)]),
    );
  }
  return value;
}

export function preview(value: unknown): string {
  if (value === undefined || value === null) return '';
  const s = typeof value === 'string' ? value : JSON.stringify(redact(value));
  return s.length > LOG_LIMIT ? `${s.slice(0, LOG_LIMIT)}…(+${s.length - LOG_LIMIT} tecken)` : s;
}

export async function requestJson<T>(
  fetchImpl: FetchLike,
  baseUrl: string,
  method: HttpMethod,
  path: string,
  opts: { token?: string | null; body?: unknown } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;

  let res: Response;
  let text: string;
  try {
    res = await fetchImpl(`${baseUrl}${path}`, {
      method,
      headers,
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    text = await res.text();
  } catch (e) {
    throw new NetworkError(method, path, e);
  }

  let body: unknown = null;
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = text;
    }
  }

  if (res.ok) return body as T;

  const message = isRecord(body) && typeof body.message === 'string' ? body.message : `HTTP ${res.status}`;
  // 401 och 409 är väntade vägar (ny spelare, versionskonflikt); övriga fel är fel.
  const log = res.status === 401 || res.status === 409 ? console.warn : console.error;
  log(`[computercat] ${method} ${path} → ${res.status}`, { request: preview(opts.body), response: preview(body) });
  throw new ApiError(res.status, message, body, method, path);
}

export type MetaResult<T> = { status: number; body: T | null; etag: string | null };

/**
 * Timeouten plus anroparens egen signal: den som avbryter först vinner. `dispose` tar bort lyssnare
 * och timer, så att en långlivad signal (bevakningens stopp) inte samlar på sig en lyssnare per anrop.
 */
function withTimeout(signal: AbortSignal | undefined): { signal: AbortSignal; dispose: () => void } {
  if (!signal) return { signal: AbortSignal.timeout(TIMEOUT_MS), dispose: () => {} };
  if (typeof AbortSignal.any === 'function') {
    return { signal: AbortSignal.any([signal, AbortSignal.timeout(TIMEOUT_MS)]), dispose: () => {} };
  }
  // Reserv utan AbortSignal.any (Safari före 17.4): egen controller och egen timer.
  const ctrl = new AbortController();
  const onAbort = () => ctrl.abort(signal.reason);
  const timer = setTimeout(() => ctrl.abort(new DOMException('Tidsgränsen nåddes', 'TimeoutError')), TIMEOUT_MS);
  const dispose = () => {
    clearTimeout(timer);
    signal.removeEventListener('abort', onAbort);
  };
  if (signal.aborted) {
    ctrl.abort(signal.reason);
    clearTimeout(timer);
  } else {
    signal.addEventListener('abort', onAbort, { once: true });
  }
  return { signal: ctrl.signal, dispose };
}

/**
 * Som requestJson men svarar med status och ETag. 304 (oförändrat) är inget fel och loggas inte:
 * body är då null. Övriga statusar utanför 2xx kastar ApiError och loggas precis som i requestJson.
 */
export async function requestJsonMeta<T>(
  fetchImpl: FetchLike,
  baseUrl: string,
  method: HttpMethod,
  path: string,
  opts: { token?: string | null; body?: unknown; headers?: Record<string, string>; noStore?: boolean; signal?: AbortSignal } = {},
): Promise<MetaResult<T>> {
  // En egen Authorization-header (oavsett skiftläge) tas bort: bara token får styra den.
  const custom = Object.entries(opts.headers ?? {}).filter(([k]) => !/^authorization$/i.test(k));
  const headers: Record<string, string> = { Accept: 'application/json', ...Object.fromEntries(custom) };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;

  let res: Response;
  let text: string;
  const abort = withTimeout(opts.signal);
  try {
    const init: RequestInit = {
      method,
      headers,
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
      signal: abort.signal,
    };
    if (opts.noStore) init.cache = 'no-store';
    res = await fetchImpl(`${baseUrl}${path}`, init);
    text = await res.text();
  } catch (e) {
    throw new NetworkError(method, path, e);
  } finally {
    abort.dispose();
  }

  const etag = res.headers.get('ETag');
  if (res.status === 304) return { status: 304, body: null, etag };

  let body: unknown = null;
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = text;
    }
  }

  if (res.ok) return { status: res.status, body: body as T | null, etag };

  const message = isRecord(body) && typeof body.message === 'string' ? body.message : `HTTP ${res.status}`;
  // 401 och 409 är väntade vägar (ny spelare, versionskonflikt); övriga fel är fel.
  const log = res.status === 401 || res.status === 409 ? console.warn : console.error;
  log(`[computercat] ${method} ${path} → ${res.status}`, { request: preview(opts.body), response: preview(body) });
  // Retry-After i sekundform (Laravels throttle skickar så); HTTP-datum tolkas inte och ger null.
  const retryHeader = res.headers.get('Retry-After')?.trim() ?? '';
  const retryAfter = /^\d+$/.test(retryHeader) ? Number(retryHeader) : null;
  throw new ApiError(res.status, message, body, method, path, retryAfter);
}
