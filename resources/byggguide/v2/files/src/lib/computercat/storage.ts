export interface KeyValueStore {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export function memoryStorage(): KeyValueStore {
  const map = new Map<string, string>();
  return {
    getItem: (k) => map.get(k) ?? null,
    setItem: (k, v) => {
      map.set(k, v);
    },
    removeItem: (k) => {
      map.delete(k);
    },
  };
}

/** localStorage om det går att skriva till, annars minne (med en synlig varning). */
export function browserStorage(): KeyValueStore {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      const probe = '__computercat_probe';
      window.localStorage.setItem(probe, '1');
      window.localStorage.removeItem(probe);
      return window.localStorage;
    }
  } catch {
    // Privat läge eller blockerad lagring: faller till minne nedan.
  }
  console.warn('[computercat] localStorage är inte tillgängligt; token och kö finns bara tills sidan laddas om.');
  return memoryStorage();
}

export function readJson<T>(storage: KeyValueStore, key: string, guard: (v: unknown) => v is T): T | null {
  const raw = storage.getItem(key);
  if (raw === null) return null;
  try {
    const value: unknown = JSON.parse(raw);
    if (guard(value)) return value;
  } catch {
    // Trasig JSON hanteras som fel form nedan.
  }
  console.warn(`[computercat] ogiltigt värde i ${key} ignoreras`);
  storage.removeItem(key);
  return null;
}

/** Returnerar false (och loggar) om det inte gick att spara, så att anroparen kan göra felet synligt. */
export function writeJson(storage: KeyValueStore, key: string, value: unknown): boolean {
  try {
    storage.setItem(key, JSON.stringify(value));
    return true;
  } catch (e) {
    console.error(`[computercat] kunde inte spara ${key}`, e);
    return false;
  }
}
