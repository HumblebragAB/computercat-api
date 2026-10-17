import { ApiError, NetworkError, isRecord, requestJson, requestJsonMeta, type FetchLike, type HttpMethod, type MetaResult } from './http';
import { readJson, writeJson, type KeyValueStore } from './storage';

export type Session = { token: string; userId: number; displayName: string; issuedAt?: number };

/** Token som är äldre än så här förnyas vid start (POST /auth/refresh). */
export const REFRESH_AFTER_MS = 24 * 60 * 60 * 1000;
export type Authed = <T>(method: HttpMethod, path: string, body?: unknown) => Promise<T>;
export type MetaOptions = { body?: unknown; headers?: Record<string, string>; noStore?: boolean; signal?: AbortSignal };
export type AuthedMeta = <T>(method: HttpMethod, path: string, opts?: MetaOptions) => Promise<MetaResult<T>>;

type ApiUser = { id: number; display_name: string; is_anonymous: boolean };

function isApiUser(v: unknown): v is ApiUser {
  return isRecord(v) && typeof v.id === 'number' && typeof v.display_name === 'string' && typeof v.is_anonymous === 'boolean';
}

function isSession(v: unknown): v is Session {
  return isRecord(v) && typeof v.token === 'string' && v.token.length > 0 && typeof v.userId === 'number' && typeof v.displayName === 'string' && (v.issuedAt === undefined || typeof v.issuedAt === 'number');
}

export type AuthDeps = { fetch: FetchLike; baseUrl: string; game: string; storage: KeyValueStore; now?: () => number };

export function createAuth(deps: AuthDeps) {
  const key = `computercat:${deps.game}:session`;
  let session: Session | null = readJson(deps.storage, key, isSession);
  let creating: Promise<Session> | null = null;
  const now = deps.now ?? Date.now;
  let startupChecked = false;
  let refreshing: Promise<Session> | null = null;
  let refreshWarned = false;

  function remember(next: Session): Session {
    session = next;
    writeJson(deps.storage, key, next);
    return next;
  }

  async function createAnonymous(): Promise<Session> {
    // AuthController::anonymous svarar 201 {user, token} (AuthController.php:34-37).
    const res = await requestJson<unknown>(deps.fetch, deps.baseUrl, 'POST', '/auth/anonymous', { body: {} });
    if (!isRecord(res) || typeof res.token !== 'string' || !isApiUser(res.user)) {
      throw new Error('Oväntat svar från POST /auth/anonymous (token eller user saknas)');
    }
    return remember({ token: res.token, userId: res.user.id, displayName: res.user.display_name, issuedAt: now() });
  }

  /** Sparad spelare, annars en ny anonym. Samtidiga anrop delar på samma skapande. */
  function getSession(): Promise<Session> {
    if (session && !startupChecked) {
      startupChecked = true;
      if (session.issuedAt !== undefined && now() - session.issuedAt > REFRESH_AFTER_MS) {
        refreshing = refresh(session).finally(() => {
          refreshing = null;
        });
      }
    }
    if (refreshing) return refreshing;
    if (session) return Promise.resolve(session);
    // En annan flik kan redan ha skapat en ny spelare: använd den i stället för att skapa ännu en.
    session = readJson(deps.storage, key, isSession);
    if (session) return Promise.resolve(session);
    if (!creating) {
      creating = createAnonymous().finally(() => {
        creating = null;
      });
    }
    return creating;
  }

  /**
   * Förnya en gammal token (POST /auth/refresh, den gamla gäller ~10 min till). Bara en gång per start.
   * 404 (ej driftsatt), nätverksfel och serverfel behåller token. 401 ger en ny anonym spelare.
   * Den nya token sparas bara om lagringen fortfarande håller den token vi förnyade; annars har en
   * annan flik hunnit före och vi använder dess token.
   */
  async function refresh(old: Session): Promise<Session> {
    let res: unknown;
    try {
      res = await requestJson<unknown>(deps.fetch, deps.baseUrl, 'POST', '/auth/refresh', { token: old.token });
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        discard(old);
        refreshing = null; // annars väntar getSession på sig själv
        return getSession();
      }
      if (!refreshWarned) {
        refreshWarned = true;
        const why = e instanceof ApiError && e.status === 404 ? 'finns inte på servern ännu' : e instanceof NetworkError ? 'nätet saknas' : 'misslyckades';
        console.warn(`[computercat] tokenförnyelse hoppades över (${why}); behåller nuvarande token`);
      }
      return old;
    }
    if (!isRecord(res) || typeof res.token !== 'string' || !isApiUser(res.user)) {
      console.error('[computercat] oväntat svar från POST /auth/refresh; behåller nuvarande token');
      return old;
    }
    const fresh: Session = { token: res.token, userId: res.user.id, displayName: res.user.display_name, issuedAt: now() };
    const inStorage = readJson(deps.storage, key, isSession);
    if (inStorage && inStorage.token !== old.token) {
      session = inStorage;
      return inStorage;
    }
    return remember(fresh);
  }

  /** Glöm token bara om den fortfarande är den som fick 401 (ett parallellt anrop kan redan ha bytt). */
  function discard(stale: Session): void {
    if (session?.token === stale.token) session = null;
    // Ta bara bort den sparade token om det är den som misslyckades; en annan flik kan ha en ny.
    if (readJson(deps.storage, key, isSession)?.token === stale.token) deps.storage.removeItem(key);
  }

  const authed: Authed = async <T>(method: HttpMethod, path: string, body?: unknown): Promise<T> => {
    const first = await getSession();
    try {
      return await requestJson<T>(deps.fetch, deps.baseUrl, method, path, { token: first.token, body });
    } catch (e) {
      // Bara 401 betyder utgången token (Sanctum, 90 dygn; förnyas vid start). Nätverksfel och 5xx behåller spelaren.
      if (!(e instanceof ApiError) || e.status !== 401) throw e;
      discard(first);
      const fresh = await getSession();
      return requestJson<T>(deps.fetch, deps.baseUrl, method, path, { token: fresh.token, body });
    }
  };

  /** Som authed men med status och ETag (requestJsonMeta). Samma 401-logik: ny spelare, ett nytt försök. */
  const authedMeta: AuthedMeta = async <T>(method: HttpMethod, path: string, opts: MetaOptions = {}): Promise<MetaResult<T>> => {
    const first = await getSession();
    try {
      return await requestJsonMeta<T>(deps.fetch, deps.baseUrl, method, path, { ...opts, token: first.token });
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) throw e;
      discard(first);
      const fresh = await getSession();
      return requestJsonMeta<T>(deps.fetch, deps.baseUrl, method, path, { ...opts, token: fresh.token });
    }
  };

  /** Kontrollera token vid start. AuthController::me svarar {user}, inte {data} (AuthController.php:106-111). */
  async function verify(): Promise<Session> {
    const res = await authed<unknown>('GET', '/auth/me');
    const user = isRecord(res) ? res.user : undefined;
    if (!isApiUser(user)) throw new Error('Oväntat svar från GET /auth/me (user saknas)');
    const current = await getSession();
    return remember({ ...current, userId: user.id, displayName: user.display_name });
  }

  return { getSession, current: () => session, authed, authedMeta, verify };
}
