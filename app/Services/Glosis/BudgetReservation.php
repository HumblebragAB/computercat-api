<?php

namespace App\Services\Glosis;

/** En reserverad del av månadsbudgeten, se AiBudget. */
final class BudgetReservation
{
    public bool $closed = false;

    public function __construct(
        public readonly string $key,
        public readonly int $microUsd,
        /** Nivån reservationen gjordes för (GlosisCaller::GULD|FREE|ADMIN); record() bokför raden på den. */
        public readonly string $tier = GlosisCaller::GULD,
        /** Gratisnivåns delbudget, se AiBudget::freeMonthKey(). Satt om och endast om tier är FREE. */
        public readonly ?string $freeKey = null,
    ) {}
}
