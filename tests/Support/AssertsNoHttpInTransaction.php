<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Spec 2c: "Ingen HTTP-anrop sker inne i en databastransaktion." Varje
// HTTP-anrop under testet registrerar transaktionsnivån; den ska vara
// densamma som när testet började (RefreshDatabase har då öppnat sin egen).
trait AssertsNoHttpInTransaction
{
    /** @var list<array{level: int, url: string}> */
    private array $httpTransactionLevels = [];

    private int $baseTransactionLevel = 0;

    protected function startHttpTransactionGuard(): void
    {
        $this->baseTransactionLevel = DB::transactionLevel();
        Http::globalRequestMiddleware(function ($request) {
            $this->httpTransactionLevels[] = ['level' => DB::transactionLevel(), 'url' => (string) $request->getUri()];

            return $request;
        });
    }

    protected function assertNoHttpInTransaction(): void
    {
        foreach ($this->httpTransactionLevels as $call) {
            $this->assertSame($this->baseTransactionLevel, $call['level'], "HTTP-anrop inne i en databastransaktion: {$call['url']}");
        }
    }
}
