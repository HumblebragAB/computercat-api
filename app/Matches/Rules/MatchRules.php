<?php

namespace App\Matches\Rules;

use App\Matches\MatchContext;

interface MatchRules
{
    /**
     * @return array normaliserade options; kastar ValidationException
     */
    public function validateOptions(array $options): array;

    /** Publik startstate innan några händelser. */
    public function initialState(array $options): array;

    /** Ren reducer: publik state + en händelse → ny publik state. Får inte läsa dold state. */
    public function apply(array $state, string $type, ?int $seat, array $payload): array;

    /** Anropas när andra spelaren gått med. */
    public function start(MatchContext $ctx): void;

    /** Ett drag; kastar IllegalMove. */
    public function act(MatchContext $ctx, int $seat, string $type, array $payload): void;

    /** Vad spelaren själv får se av sin dolda state. */
    public function view(?array $hidden): array;
}
