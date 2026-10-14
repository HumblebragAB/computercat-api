export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH';
export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export const TIMEOUT_MS = 10_000;
const LOG_LIMIT = 4096;

export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  readonly method: HttpMethod;
  readonly path: string;

  constructor(status: number, message: string, body: unknown, method: HttpMethod, path: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
    this.method = method;
    this.path = path;
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
