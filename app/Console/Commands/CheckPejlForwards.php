<?php

namespace App\Console\Commands;

use App\Jobs\SendPejlForward;
use App\Models\Game;
use App\Models\PejlForward;
use App\Services\Pejl\PejlClient;
use App\Support\SlackAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daglig kontroll av vidarebefordringarna till Pejl:
 *  1. rader som legat pending eller failed i över ett dygn larmar (antal + äldsta id); pending äldre än
 *     en timme dispatchas om (jobbet är ShouldBeUnique, så en redan köad rad dubbleras inte),
 *     Återbetalningar och återkallelser som väntar på ett köp i waiting_for_source räknas inte,
 *  2. spel med waiting_for_source-rader (saknas i Pejls config/revenue_sources.php) larmar en gång per spel
 *     och dygn (Stockholmsdatum i nyckeln). Raderna sätts sedan till pending och dispatchas: finns källan nu
 *     går de in, annars blir de waiting_for_source igen utan larm,
 *  3. för varje spel med sent-rader och innevarande + föregående månad: antal, summa och kostnad mot Pejls
 *     summa. Månaden räknas på occurred_at i config('pejl.timezone'). Återbetalning räknas negativ, som i Pejl.
 *
 * Exit 1 vid larm. Pejl onåbar eller nekar = larm "avstämningen kunde inte köras", aldrig tyst grönt.
 */
class CheckPejlForwards extends Command
{
    protected $signature = 'pejl:check-forwards {--month= : Bara denna månad (YYYY-MM)} {--retry-failed : Sätt alla failed-rader till pending och dispatcha dem}';

    protected $description = 'Larmar om vidarebefordringar som fastnat och stämmer av summorna mot Pejl';

    private const TOLERANCE = 0.01;

    public function handle(PejlClient $client, SlackAlert $slack): int
    {
        if ($this->option('retry-failed')) {
            return $this->retryFailed();
        }

        $months = $this->months();
        if ($months === null) {
            $this->error('--month måste vara YYYY-MM.');

            return self::INVALID;
        }

        $waiting = PejlForward::with('game')->where('status', 'waiting_for_source')->orderBy('id')->get();
        $alerts = array_merge($this->stuckRows(), $this->waitingForSource($waiting), $this->reconcile($client, $months));

        foreach ($alerts as $key => $text) {
            $this->error($text);
            Log::error('Pejl: kontrollen larmar', ['key' => $key, 'text' => $text]);
            $slack->send($key, SlackAlert::escape($text));
        }

        if ($alerts === []) {
            $this->info('Vidarebefordringarna till Pejl stämmer.');
        }

        $this->redispatchWaiting($waiting);

        return $alerts === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, PejlForward>  $waiting
     * @return array<string, string>
     */
    private function waitingForSource(Collection $waiting): array
    {
        $date = CarbonImmutable::now(config('pejl.timezone'))->toDateString();
        $alerts = [];

        foreach ($waiting->groupBy(fn (PejlForward $f) => $f->game->slug) as $slug => $rows) {
            $oldest = $rows->min('occurred_at')?->copy()->setTimezone(config('pejl.timezone'))->format('Y-m-d H:i');
            $alerts["pejl-check-waiting-source-{$slug}-{$date}"] = sprintf(
                'Pejl: %s säljer men saknas som källa i Pejl. %d köp väntar (äldsta %s). Lägg till %s i config/revenue_sources.php i Pejl; köpen skickas då av sig själva.',
                $slug, $rows->count(), $oldest ?? 'okänt', $slug,
            );
        }

        return $alerts;
    }

    /** @param  Collection<int, PejlForward>  $waiting */
    private function redispatchWaiting(Collection $waiting): void
    {
        foreach ($waiting as $forward) {
            if (PejlForward::whereKey($forward->id)->where('status', 'waiting_for_source')->update(['status' => 'pending']) === 1) {
                SendPejlForward::dispatch($forward->id);
            }
        }
    }

    private function retryFailed(): int
    {
        $ids = PejlForward::where('status', 'failed')->orderBy('id')->pluck('id');
        PejlForward::whereIn('id', $ids)->where('status', 'failed')->update(['status' => 'pending']);
        foreach ($ids as $id) {
            SendPejlForward::dispatch($id);
        }

        $this->info("{$ids->count()} failed-rader satta till pending och dispatchade.");

        return self::SUCCESS;
    }

    /** @return list<CarbonImmutable>|null månadernas första dag i Pejls tidszon */
    private function months(): ?array
    {
        $tz = config('pejl.timezone');
        if ($option = $this->option('month')) {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $option)) {
                return null;
            }

            return [CarbonImmutable::createFromFormat('!Y-m', $option, $tz)];
        }

        $current = CarbonImmutable::now($tz)->startOfMonth();

        return [$current->subMonth(), $current];
    }

    /** @return array<string, string> */
    private function stuckRows(): array
    {
        $alerts = [];
        $cutoff = now()->subDay();

        $pending = PejlForward::where('status', 'pending')->where('created_at', '<', now()->subHour())->orderBy('id')->get();
        foreach ($pending as $forward) {
            SendPejlForward::dispatch($forward->id);
        }

        foreach (['pending' => 'väntar', 'failed' => 'fallerade'] as $status => $label) {
            $old = PejlForward::where('status', $status)->where('created_at', '<', $cutoff)
                ->when($status === 'pending', fn ($q) => $q->whereNot(fn ($q) => $this->waitsOnWaitingSale($q)))->orderBy('id');
            if (($count = $old->count()) > 0) {
                $alerts["pejl-check-stuck-{$status}"] = "Pejl: {$count} vidarebefordringar {$label} sedan över ett dygn (äldsta #{$old->value('id')}).";
            }
        }

        return $alerts;
    }

    // Återbetalning eller återkallelse vars köp väntar på att spelet läggs till som källa i Pejl.
    private function waitsOnWaitingSale(Builder $query): void
    {
        $query->where('kind', '!=', 'sale')->whereExists(fn ($sale) => $sale->from('pejl_forwards as sale')
            ->whereColumn('sale.game_id', 'pejl_forwards.game_id')
            ->whereColumn('sale.store', 'pejl_forwards.store')
            ->whereColumn('sale.transaction_id', 'pejl_forwards.transaction_id')
            ->where('sale.kind', 'sale')
            ->where('sale.status', 'waiting_for_source'));
    }

    /**
     * @param  list<CarbonImmutable>  $months
     * @return array<string, string>
     */
    private function reconcile(PejlClient $client, array $months): array
    {
        $alerts = [];
        $games = Game::whereIn('id', PejlForward::where('status', 'sent')->select('game_id'))->get();

        foreach ($games as $game) {
            foreach ($months as $month) {
                $label = $month->format('Y-m');
                try {
                    $theirs = $client->summary($game->slug, $month->year, $month->month);
                } catch (Throwable $e) {
                    $alerts["pejl-check-error-{$game->slug}"] = "Pejl: avstämningen kunde inte köras för {$game->slug}: ".mb_strcut($client->redact($e->getMessage()), 0, 300);

                    continue 2;
                }

                $ours = $this->ourTotals($game, $month);
                $differs = $ours['rows'] !== $theirs['rows']
                    || abs($ours['price'] - $theirs['price']) > self::TOLERANCE
                    || abs($ours['cost'] - $theirs['cost']) > self::TOLERANCE;
                if ($differs) {
                    $alerts["pejl-check-diff-{$game->slug}-{$label}"] = sprintf(
                        'Pejl: avstämningen för %s %s stämmer inte. Computercat: %d rader, %.2f kr, kostnad %.2f kr. Pejl: %d rader, %.2f kr, kostnad %.2f kr.',
                        $game->slug, $label, $ours['rows'], $ours['price'], $ours['cost'], $theirs['rows'], $theirs['price'], $theirs['cost'],
                    );
                }
            }
        }

        return $alerts;
    }

    /** @return array{rows: int, price: float, cost: float} */
    private function ourTotals(Game $game, CarbonImmutable $month): array
    {
        $totals = ['rows' => 0, 'price' => 0.0, 'cost' => 0.0];
        PejlForward::where('game_id', $game->id)->where('status', 'sent')
            ->where('occurred_at', '>=', $month->utc())
            ->where('occurred_at', '<', $month->addMonth()->utc())
            ->each(function (PejlForward $f) use (&$totals) {
                $sign = $f->kind === 'refund' ? -1 : 1;
                $totals['rows']++;
                $totals['price'] += $sign * (float) $f->amount_ex_vat_sek;
                $totals['cost'] += $sign * (float) $f->cost_sek;
            });

        return $totals;
    }
}
