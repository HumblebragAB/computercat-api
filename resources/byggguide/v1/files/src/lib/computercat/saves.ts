import type { Authed } from './auth';
import { ApiError, isRecord } from './http';

/** GameSaveResource.php:12-18. Nyckeln `ownership` tas bort av servern (GameSaveController.php:59-62). */
export type CloudSave<T> = {
  save_key: string;
  data: T;
  version: number;
  checksum: string | null;
  saved_at: string | null;
};
export type PutResult<T> = { ok: true; save: CloudSave<T> } | { ok: false; conflict: CloudSave<T> };

const seg = encodeURIComponent;

function asSave<T>(v: unknown, what: string): CloudSave<T> {
  if (!isRecord(v) || !isRecord(v.data) || typeof v.version !== 'number') throw new Error(`Oväntat svar från ${what}`);
  return v as unknown as CloudSave<T>;
}

export function createSaves(game: string, authed: Authed) {
  const base = `/games/${seg(game)}/saves`;

  async function get<T>(key: string): Promise<CloudSave<T> | null> {
    const path = `${base}/${seg(key)}`;
    try {
      const res = await authed<unknown>('GET', path);
      return asSave<T>(isRecord(res) ? res.data : undefined, `GET ${path}`);
    } catch (e) {
      // Bara "Save not found." betyder tom sparning; "Game not found." är felkonfiguration.
      if (e instanceof ApiError && e.status === 404 && e.message === 'Save not found.') return null;
      throw e;
    }
  }

  /** version = den version du senast läste (0 om ingen sparning finns). Servern ger version + 1. */
  async function put<T extends Record<string, unknown>>(key: string, data: T, version: number): Promise<PutResult<T>> {
    if (Object.keys(data).length === 0) {
      throw new RangeError('data får inte vara ett tomt objekt (servern kräver required|array)');
    }
    if (!Number.isSafeInteger(version) || version < 0) throw new RangeError(`ogiltig version ${version}`);
    const path = `${base}/${seg(key)}`;
    try {
      const res = await authed<unknown>('PUT', path, { data, version });
      return { ok: true, save: asSave<T>(isRecord(res) ? res.data : undefined, `PUT ${path}`) };
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && isRecord(e.body)) {
        return { ok: false, conflict: asSave<T>(e.body.data, `PUT ${path} (409)`) };
      }
      throw e;
    }
  }

  /** Hämta, slå ihop, skicka; vid 409 slå ihop med serverns kopia och försök en gång till. */
  async function sync<T extends Record<string, unknown>>(
    key: string,
    local: T,
    merge: (local: T, cloud: T) => T,
  ): Promise<CloudSave<T>> {
    const cloud = await get<T>(key);
    const merged = cloud ? merge(local, cloud.data) : local;
    const first = await put(key, merged, cloud?.version ?? 0);
    if (first.ok) return first.save;
    const second = await put(key, merge(merged, first.conflict.data), first.conflict.version);
    if (second.ok) return second.save;
    throw new Error(`Sparningen ${key} krockade två gånger i rad; försök igen senare`);
  }

  return { get, put, sync };
}
