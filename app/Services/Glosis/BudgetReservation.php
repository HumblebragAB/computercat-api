<?php

namespace App\Services\Glosis;

/** En reserverad del av månadsbudgeten, se AiBudget. */
final class BudgetReservation
{
    public bool $closed = false;

    public function __construct(
        public readonly string $key,
        public readonly int $microUsd,
    ) {}
}
