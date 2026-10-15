<?php

namespace App\Releases;

use App\Mcp\GameAccess;
use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use App\Releases\TestFlight\TestFlightPreflight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Befordran, godkännande och byggstart. Allt som skriver ett släpp går genom
// här. Inget HTTP-anrop sker i transaktionerna; Slack skickas av anroparen efteråt.
final class ReleaseService
{
    /** @throws ReleaseRefused */
    public function promote(Game $game, User $user, string $target, string $stage, ?string $commit): Release
    {
        if (! GameAccess::isOwner($user, $game)) { // R2
            throw new ReleaseRefused('Only the game owner can promote a release.');
        }

        // Utanför transaktionen: en utgången plats ska vara fri när låset tas.
        Release::expireStale();

        // Steg som Computercat bygger läser spelrepot först (HTTP, därför före transaktionen).
        // Commiten bestäms här och kontrolleras igen under låset.
        $preflight = null;
        if (ReleaseCatalog::isBuiltByComputercat($stage)) {
            $commit ??= DemoBuild::latestFor($game)?->commit_sha;
            if (($why = ReleaseGate::check($game, $target, $stage, $commit)) !== null) {
                throw new ReleaseRefused($why);
            }
            if (Release::active()->where('game_id', $game->id)->where('target', $target)->where('stage', $stage)->exists()) {
                throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
            }
            $preflight = app(TestFlightPreflight::class)->check($game->fresh(), $commit);
        }

        try {
            return DB::transaction(function () use ($game, $user, $target, $stage, $commit, $preflight) {
                // Spärr 4: spelets rad låses, så två samtidiga befordringar köas efter varandra.
                // (SQLite i test ignorerar låset; det unika indexet är då det som håller.)
                $locked = Game::query()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

                $production = ReleaseCatalog::isProduction($target, $stage);
                if ($commit === null && ! $production) { // spärr 8: standardvalet gäller bara teststeg
                    $commit = DemoBuild::latestFor($locked)?->commit_sha;
                }

                if (($why = ReleaseGate::check($locked, $target, $stage, $commit)) !== null) {
                    throw new ReleaseRefused($why);
                }

                if (Release::active()->where('game_id', $locked->id)->where('target', $target)->where('stage', $stage)->exists()) {
                    throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
                }

                // Spärr 3, beräknat i samma transaktion som skrivningen.
                $first = $production && ! Release::where('game_id', $locked->id)->where('target', $target)
                    ->where('stage', $stage)->where('status', Release::SUCCEEDED)->exists();

                $build = [];
                if ($preflight !== null) {
                    // R3 igen under låset: ett annat släpp kan ha lyckats sedan förkontrollen.
                    $last = TestFlightPreflight::lastReleased($locked);
                    if ($last !== null && ! $preflight->version->isHigherThan($last)) {
                        throw new ReleaseRefused("Version {$preflight->version} is no longer higher than the latest TestFlight release {$last}; raise version in package.json and try again.");
                    }
                    // R5: nästa nummer, i samma transaktion som släppet. Luckor är tillåtna, upprepning aldrig.
                    // Räknaren får aldrig ligga efter släppen (t.ex. efter en återställning): då hoppar vi
                    // förbi högsta använda numret och loggar, i stället för att krocka med det unika indexet.
                    $counter = (int) $locked->ios_build_number;
                    $used = (int) Release::where('game_id', $locked->id)->max('build_number');
                    if ($used > $counter) {
                        Log::warning('ios_build_number låg efter släppen; hoppar förbi högsta använda byggnumret', ['game_id' => $locked->id, 'counter' => $counter, 'highest_used' => $used]);
                    }
                    $next = max($counter, $used) + 1;
                    $locked->forceFill(['ios_build_number' => $next])->save();
                    $build = ['marketing_version' => (string) $preflight->version, 'build_number' => $next, 'build_guide' => $preflight->buildGuide];
                }

                return Release::create([
                    'game_id' => $locked->id,
                    'target' => $target,
                    'stage' => $stage,
                    'commit_sha' => $commit,
                    'status' => $first ? Release::AWAITING_APPROVAL : Release::REQUESTED,
                    'requested_by' => $user->id,
                ] + $build);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
        }
    }

    /** @throws ReleaseRefused */
    public function approve(Release $release, User $admin): Release
    {
        $this->assertAdmin($admin);
        Release::expireStale();

        return DB::transaction(function () use ($release, $admin) {
            $locked = $this->lockedAwaiting($release);

            // Spärr 7: samma kontroll som vid begäran, nu mot dagens läge.
            if (($why = ReleaseGate::check($locked->game, $locked->target, $locked->stage, $locked->commit_sha)) !== null) {
                throw new ReleaseRefused($why);
            }

            $self = $locked->requested_by !== null && (int) $locked->requested_by === (int) $admin->id;
            $locked->update(['status' => Release::REQUESTED, 'approved_by' => $admin->id, 'approved_at' => now(), 'self_approved' => $self]);
            if ($self) {
                Log::notice('Release self-approved', ['release' => $locked->uuid, 'game_id' => $locked->game_id, 'user_id' => $admin->id]);
            }

            return $locked;
        });
    }

    /** @throws ReleaseRefused */
    public function reject(Release $release, User $admin): Release
    {
        $this->assertAdmin($admin);
        Release::expireStale();

        return DB::transaction(function () use ($release, $admin) {
            $locked = $this->lockedAwaiting($release);
            $locked->update(['status' => Release::CANCELLED, 'last_error' => 'Rejected by a platform admin.']);
            Log::notice('Släpp avslaget', ['release_uuid' => $locked->uuid, 'admin_id' => $admin->id]);

            return $locked;
        });
    }

    /** Avbryter ett släpp som inte börjat byggas (requested eller awaiting_approval). @throws ReleaseRefused */
    public function cancel(Release $release, User $admin): Release
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($release, $admin) {
            $locked = Release::whereKey($release->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [Release::REQUESTED, Release::AWAITING_APPROVAL], true)) {
                throw new ReleaseRefused("Release is {$locked->status}; only a requested or awaiting_approval release can be cancelled.");
            }
            $locked->update(['status' => Release::CANCELLED, 'last_error' => 'Avbrutet av en platform admin.']);
            Log::notice('Släpp avbrutet', ['release_uuid' => $locked->uuid, 'admin_id' => $admin->id]);

            return $locked;
        });
    }

    /**
     * Anropas av bygget (etapp 1b, 2, 3) precis innan det startar. Spärr 7 och 9.
     *
     * @throws ReleaseRefused
     */
    public function assertCanStart(Release $release): void
    {
        Release::expireStale();

        $fresh = Release::with('game')->find($release->getKey());
        if ($fresh === null || $fresh->status !== Release::REQUESTED) {
            throw new ReleaseRefused('Release is not in requested state.');
        }

        if (($why = ReleaseGate::check($fresh->game, $fresh->target, $fresh->stage, $fresh->commit_sha)) !== null) {
            throw new ReleaseRefused($why);
        }

        // Ett produktionssläpp startar bara om en admin godkänt det, eller om målet redan haft ett lyckat produktionssläpp.
        if (ReleaseCatalog::isProduction($fresh->target, $fresh->stage) && $fresh->approved_at === null
            && ! Release::where('game_id', $fresh->game_id)->where('target', $fresh->target)->where('stage', $fresh->stage)->where('status', Release::SUCCEEDED)->exists()) {
            throw new ReleaseRefused('The first production release has not been approved by a platform admin.');
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isPlatformAdmin()) {
            throw new ReleaseRefused('Only a platform admin can approve, reject or cancel a release.');
        }
    }

    private function lockedAwaiting(Release $release): Release
    {
        $locked = Release::with('game')->whereKey($release->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== Release::AWAITING_APPROVAL) {
            throw new ReleaseRefused("Release is {$locked->status}, not awaiting approval.");
        }

        return $locked;
    }
}
