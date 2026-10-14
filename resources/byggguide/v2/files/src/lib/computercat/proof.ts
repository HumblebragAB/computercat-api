/**
 * Bevis på spel för topplistor med anti-cheat (AntiCheatService.php:69-103).
 *
 * Skicka som metadata.proof. Servern kontrollerar signaturen och att |t - score|
 * är högst proof_time_tolerance (standard 1000). För topplistor där score inte
 * är en tid måste spelets anti_cheat-inställning ha proof_time_tolerance = 0,
 * annars avvisas beviset med "Proof time does not match score."
 */
export type PlayProof = { pid: string; mc: number; gh: string; t: number; sig: string };

/** FNV-1a 32-bit över UTF-8-bytes, base 36; samma som AntiCheatService::fnv1aSignature. */
export function fnv1aSignature(pid: string, mc: number, gh: string, t: number): string {
  const bytes = new TextEncoder().encode(`${pid}|${mc}|${gh}|${t}`);
  let h = 0x811c9dc5;
  for (const b of bytes) {
    h ^= b;
    h = Math.imul(h, 0x01000193);
  }
  return (h >>> 0).toString(36);
}

// PHP:s `! $pid` är sant för "" och "0" (AntiCheatService.php:77).
const phpTruthy = (s: string) => s !== '' && s !== '0';

export function createProof(input: { pid: string; moves: number; stateHash: string; elapsedMs: number }): PlayProof {
  const { pid, moves, stateHash, elapsedMs } = input;
  if (!phpTruthy(pid) || !phpTruthy(stateHash)) throw new RangeError('pid och stateHash får inte vara "" eller "0"');
  if (!Number.isSafeInteger(moves) || !Number.isSafeInteger(elapsedMs)) {
    throw new RangeError('moves och elapsedMs måste vara heltal');
  }
  return { pid, mc: moves, gh: stateHash, t: elapsedMs, sig: fnv1aSignature(pid, moves, stateHash, elapsedMs) };
}
