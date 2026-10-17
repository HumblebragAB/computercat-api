<?php

namespace Tests\Unit\Matches;

use App\Matches\MatchContext;
use App\Matches\Rules\MatchRules;

/** Minimal regelklass för kontrakts- och registertester. */
class FakeRules implements MatchRules
{
    public function validateOptions(array $options): array
    {
        return $options;
    }

    public function initialState(array $options): array
    {
        return ['log' => []];
    }

    public function apply(array $state, string $type, ?int $seat, array $payload): array
    {
        $state['log'][] = [$type, $seat];

        return $state;
    }

    public function start(MatchContext $ctx): void {}

    public function act(MatchContext $ctx, int $seat, string $type, array $payload): void {}

    public function view(?array $hidden): array
    {
        return $hidden ?? [];
    }
}
