<?php

namespace App\Mcp;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\User;

class AuditLogger
{
    public const OUTCOMES = ['ok', 'validation_error', 'denied', 'error'];

    private const MAX_STRING = 200;

    public static function record(?User $user, ?Game $game, string $tool, array $arguments, string $outcome): void
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Ogiltigt outcome: {$outcome}");
        }

        McpAuditLog::create([
            'user_id' => $user?->getKey(),
            'game_id' => $game?->getKey(),
            // Kolumnen är varchar(64); ett längre namn ska inte få loggningen att kasta.
            'tool' => mb_substr($tool, 0, 64),
            'arguments' => self::summarize($arguments),
            'outcome' => $outcome,
        ]);
    }

    // Alltid giltig JSON och liten: listor och objekt blir en beskrivning.
    public static function summarize(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $key => $value) {
            $out[$key] = match (true) {
                is_string($value) => self::shorten($value),
                is_array($value) && array_is_list($value) => '[list: '.count($value).' items]',
                is_array($value) => '[object: '.count($value).' keys]',
                is_object($value) => '[object]',
                is_null($value), is_bool($value), is_int($value) => $value,
                is_float($value) => is_finite($value) ? $value : (string) $value,
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }

    private static function shorten(string $value): string
    {
        // Ogiltig UTF-8 skulle få json_encode att misslyckas.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
    }
}
