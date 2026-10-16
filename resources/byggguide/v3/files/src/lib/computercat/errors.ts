import { ApiError, NetworkError } from './http';

/** Text för spelaren/utvecklaren. Meddelandena matchar LeaderboardController.php:25 och ResolveGame.php:20. */
export function describeError(e: unknown): string {
  if (e instanceof NetworkError) return 'Offline: kunde inte nå Computercat. Det du spelar sparas lokalt.';
  if (e instanceof ApiError) {
    if (e.status === 404 && e.message === 'Leaderboard type not found.') {
      return 'Topplistan finns inte i Computercat ännu. Skapa den med upsert_leaderboard_type.';
    }
    if (e.status === 404 && e.message === 'Game not found.') {
      return 'Spelet finns inte eller är inaktivt i Computercat (kontrollera NEXT_PUBLIC_COMPUTERCAT_GAME).';
    }
    if (e.status === 429) return 'För många anrop just nu. Försök igen om en minut.';
    return `Computercat svarade ${e.status}: ${e.message}`;
  }
  return e instanceof Error ? e.message : String(e);
}
