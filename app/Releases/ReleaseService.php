<?php

namespace App\Releases;

use App\Mcp\GameAccess;
use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\User;
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

        try {
            return DB::transaction(function () use ($game, $user, $target, $stage, $commit) {
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

                return Release::create([
                    'game_id' => $locked->id,
                    'target' => $target,
                    'stage' => $stage,
                    'commit_sha' => $commit,
                    'status' => $first ? Release::AWAITING_APPROVAL : Release::REQUESTED,
                    'requested_by' => $user->id,
                ]);
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

        return DB::transaction(function () use ($release) {
            $locked = $this->lockedAwaiting($release);
            $locked->update(['status' => Release::CANCELLED, 'last_error' => 'Rejected by a platform admin.']);

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
            throw new ReleaseRefused('Only a platform admin can approve or reject a release.');
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
