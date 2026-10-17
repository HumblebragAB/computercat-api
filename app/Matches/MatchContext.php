<?php

namespace App\Matches;

use App\Matches\Rules\MatchRules;
use LogicException;

/**
 * Arbetsyta för ett drag. Rör ingen databas: tjänsten laddar in state, kör reglerna
 * och skriver sedan emitted() och hiddenChanges() själv.
 */
class MatchContext
{
    /** Grundens egna händelser. Tjänsten loggar dem direkt, de går aldrig genom apply och uppspelning hoppar över dem. */
    public const RESERVED_TYPES = ['left', 'expired', 'rematch_requested', 'rematch'];

    /** @var list<array{type: string, seat: ?int, payload: array}> */
    private array $emitted = [];

    /** @var array<int, ?array> */
    private array $hiddenChanges = [];

    private bool $finished = false;

    /**
     * @param  array<int, ?array>  $hidden  dold state per säte
     */
    public function __construct(
        private readonly MatchRules $rules,
        private readonly array $options,
        private array $state,
        private array $hidden = [],
    ) {}

    public function state(): array
    {
        return $this->state;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function hidden(int $seat): ?array
    {
        return $this->hidden[$seat] ?? null;
    }

    public function setHidden(int $seat, ?array $hidden): void
    {
        $this->hidden[$seat] = $hidden;
        $this->hiddenChanges[$seat] = $hidden;
    }

    public function emit(string $type, ?int $seat, array $payload = []): void
    {
        if (in_array($type, self::RESERVED_TYPES, true)) {
            throw new LogicException("Event type '{$type}' is reserved for the match foundation.");
        }

        $this->state = $this->rules->apply($this->state, $type, $seat, $payload);
        $this->emitted[] = ['type' => $type, 'seat' => $seat, 'payload' => $payload];
    }

    public function finish(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** @return list<array{type: string, seat: ?int, payload: array}> */
    public function emitted(): array
    {
        return $this->emitted;
    }

    /** @return array<int, ?array> */
    public function hiddenChanges(): array
    {
        return $this->hiddenChanges;
    }
}
