<?php

namespace App\Services;

use App\Models\LeaderboardType;

/**
 * Game-agnostic anti-cheat validation for leaderboard submissions.
 *
 * All validation rules are driven by games.settings JSON — no game-specific
 * code. A new game only needs to configure its settings via the admin panel.
 *
 * Supported settings keys (all under games.settings.anti_cheat):
 *   min_score        — Minimum accepted score (default: 0)
 *   max_score        — Maximum accepted score (default: 86400)
 *   require_proof    — If true, submissions without proof are rejected
 *   proof_algorithm  — Signature algorithm: "fnv1a" (default) or "none"
 *   min_moves        — Minimum move count required in proof (default: 1)
 *   score_unit       — "ms" or "seconds" for time scores; proof t is always milliseconds
 *   proof_time_tolerance — Max difference between proof time and score, in the score's
 *                      unit (default: 1000); 0 turns the comparison off (points)
 */
class AntiCheatService
{
    public function check(LeaderboardType $type, int $score, ?array $metadata): array
    {
        if ($score < 0) {
            return ['passed' => false, 'reason' => 'Score cannot be negative.'];
        }

        $game = $type->game;
        $config = $game?->settings['anti_cheat'] ?? [];

        // Score bounds (works for any sort direction)
        $minScore = $config['min_score'] ?? 0;
        $maxScore = $config['max_score'] ?? 86400;

        if ($score < $minScore) {
            return ['passed' => false, 'reason' => 'Score is implausibly low.'];
        }

        if ($score > $maxScore) {
            return ['passed' => false, 'reason' => 'Score exceeds maximum allowed value.'];
        }

        // Proof-of-play validation (optional, configured per game)
        $proof = $metadata['proof'] ?? null;
        $requireProof = $config['require_proof'] ?? false;

        if ($requireProof && ! $proof) {
            return ['passed' => false, 'reason' => 'Proof of play is required.'];
        }

        if ($proof) {
            $result = $this->validateProof($score, $proof, $config);
            if (! $result['passed']) {
                return $result;
            }
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Validate proof-of-play. The proof format is generic:
     *   pid — identifier (puzzle ID, round ID, etc.)
     *   mc  — move/action count
     *   gh  — state hash (compact representation of final state)
     *   t   — elapsed time
     *   sig — signature over the above fields
     */
    private function validateProof(int $score, array $proof, array $config): array
    {
        $pid = $proof['pid'] ?? null;
        $mc = $proof['mc'] ?? null;
        $gh = $proof['gh'] ?? null;
        $t = $proof['t'] ?? null;
        $sig = $proof['sig'] ?? null;

        if (! $pid || ! is_int($mc) || ! $gh || ! is_int($t) || ! $sig) {
            return ['passed' => false, 'reason' => 'Invalid proof format.'];
        }

        // Verify signature (configurable algorithm)
        $algorithm = $config['proof_algorithm'] ?? 'fnv1a';
        if ($algorithm !== 'none') {
            $expectedSig = $this->computeSignature($algorithm, $pid, $mc, $gh, $t);
            if ($expectedSig !== null && $sig !== $expectedSig) {
                return ['passed' => false, 'reason' => 'Proof signature mismatch.'];
            }
        }

        // Time in proof should roughly match submitted score (if score is time-based).
        // t är alltid millisekunder (createProof i byggguiden); toleransen anges i poängens
        // enhet. Poäng som inte är tid har tolerans 0 i förvalen och kontrolleras inte här.
        $timeTolerance = $config['proof_time_tolerance'] ?? 1000;
        $proofTime = ($config['score_unit'] ?? null) === 'seconds' ? $t / 1000 : $t;
        if ($timeTolerance > 0 && abs($proofTime - $score) > $timeTolerance) {
            return ['passed' => false, 'reason' => 'Proof time does not match score.'];
        }

        // Minimum move count
        $minMoves = $config['min_moves'] ?? 1;
        if ($mc < $minMoves) {
            return ['passed' => false, 'reason' => 'Too few moves.'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Compute proof signature using the configured algorithm.
     */
    private function computeSignature(string $algorithm, string $pid, int $mc, string $gh, int $t): ?string
    {
        return match ($algorithm) {
            'fnv1a' => $this->fnv1aSignature($pid, $mc, $gh, $t),
            default => null, // Unknown algorithm — skip signature check
        };
    }

    /**
     * FNV-1a hash signature (matches JavaScript Math.imul-based implementation).
     */
    private function fnv1aSignature(string $pid, int $mc, string $gh, int $t): string
    {
        $payload = "{$pid}|{$mc}|{$gh}|{$t}";
        $h = 0x811C9DC5;

        for ($i = 0; $i < strlen($payload); $i++) {
            $h ^= ord($payload[$i]);
            $h = $this->imul32($h, 0x01000193);
        }

        $h = $h & 0xFFFFFFFF;
        if ($h < 0) {
            $h += 4294967296;
        }

        return base_convert((string) $h, 10, 36);
    }

    private function imul32(int $a, int $b): int
    {
        $result = ($a * $b) & 0xFFFFFFFF;

        if ($result >= 0x80000000) {
            $result -= 0x100000000;
        }

        return $result;
    }
}
