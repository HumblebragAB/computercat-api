export const DEFAULT_BASE_URL = 'https://api.computercat.co/api/v1';

// Samma tecken som spelslugs i Computercat; hindrar att slugen ändrar URL:en.
const GAME_SLUG = /^[a-z0-9][a-z0-9-]{0,49}$/;

export type ComputercatConfig = { game: string; baseUrl: string };
export type ConfigResult = { ok: true; config: ComputercatConfig } | { ok: false; error: string };

export function parseConfig(raw: { game?: string; url?: string }): ConfigResult {
  const game = raw.game?.trim() ?? '';
  if (!game) {
    return {
      ok: false,
      error: 'NEXT_PUBLIC_COMPUTERCAT_GAME saknas. Spelet fungerar lokalt, men inga resultat skickas till Computercat.',
    };
  }
  if (!GAME_SLUG.test(game)) {
    return { ok: false, error: `NEXT_PUBLIC_COMPUTERCAT_GAME har ogiltigt format: "${game}".` };
  }

  const url = (raw.url?.trim() || DEFAULT_BASE_URL).replace(/\/+$/, '');
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return { ok: false, error: `NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress: "${url}".` };
  }
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol !== 'https:' && !(local && parsed.protocol === 'http:')) {
    return { ok: false, error: 'NEXT_PUBLIC_COMPUTERCAT_URL måste börja med https:// (http bara mot localhost).' };
  }

  return { ok: true, config: { game, baseUrl: url } };
}

export function readConfig(): ConfigResult {
  // Next.js bakar bara in NEXT_PUBLIC_* när namnet står bokstavligt i koden.
  return parseConfig({
    game: process.env.NEXT_PUBLIC_COMPUTERCAT_GAME,
    url: process.env.NEXT_PUBLIC_COMPUTERCAT_URL,
  });
}
