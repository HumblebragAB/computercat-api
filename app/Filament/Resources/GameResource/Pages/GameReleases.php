<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use App\Models\Game;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Releases\ReleaseStatus;
use App\Releases\SetupSteps;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Log;

// Godkännande av första produktionssläppet och avbockning av engångssteg (R10: bara platform admin).
// Godkännande sker bara här, aldrig via MCP. Varje Livewire-metod kontrollerar behörigheten själv,
// eftersom metoderna kan anropas av vem som helst som kan mounta sidan.
class GameReleases extends Page
{
    use InteractsWithRecord;

    protected static string $resource = GameResource::class;

    protected static string $view = 'filament.resources.game-resource.pages.game-releases';

    protected static ?string $title = 'Releases';

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->isPlatformAdmin() === true;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getStatus(): array
    {
        return ReleaseStatus::forGame($this->getRecord()->fresh());
    }

    public function getReleases()
    {
        return $this->getRecord()->releases()->orderByDesc('id')->limit(50)->get();
    }

    public function getSteps()
    {
        return $this->getRecord()->setupSteps()->orderBy('target')->orderBy('id')->get();
    }

    public function approve(int $releaseId): void
    {
        $admin = $this->admin();
        $release = $this->getRecord()->releases()->whereKey($releaseId)->first();
        if ($release === null) {
            Notification::make()->danger()->title('Okänt släpp för det här spelet.')->send();

            return;
        }

        try {
            app(ReleaseService::class)->approve($release, $admin);
        } catch (ReleaseRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Släppet är godkänt.')->send();
    }

    public function reject(int $releaseId): void
    {
        $admin = $this->admin();
        $release = $this->getRecord()->releases()->whereKey($releaseId)->first();
        if ($release === null) {
            Notification::make()->danger()->title('Okänt släpp för det här spelet.')->send();

            return;
        }

        try {
            app(ReleaseService::class)->reject($release, $admin);
        } catch (ReleaseRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Släppet är avslaget.')->send();
    }

    public function cancel(int $releaseId): void
    {
        $admin = $this->admin();
        $release = $this->getRecord()->releases()->whereKey($releaseId)->first();
        if ($release === null) {
            Notification::make()->danger()->title('Okänt släpp för det här spelet.')->send();

            return;
        }

        try {
            app(ReleaseService::class)->cancel($release, $admin);
        } catch (ReleaseRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Släppet är avbrutet.')->send();
    }

    public function markStepDone(int $stepId): void
    {
        $admin = $this->admin();
        $step = $this->getRecord()->setupSteps()->whereKey($stepId)->first();
        if ($step === null) {
            Notification::make()->danger()->title('Okänt engångssteg för det här spelet.')->send();

            return;
        }

        // Steg som Computercat själv sätter (etapp 2) kan inte bockas av för hand.
        if (in_array($step->key, SetupSteps::AUTOMATIC, true)) {
            Notification::make()->danger()->title('Det här steget sätts av Computercat och kan inte bockas av för hand.')->send();

            return;
        }

        // Redan avbockat: skriv inte över vem som bockade av och när.
        if ($step->status === ReleaseSetupStep::DONE) {
            Notification::make()->warning()->title('Steget är redan avbockat.')->send();

            return;
        }

        $step->update(['status' => ReleaseSetupStep::DONE, 'done_by' => $admin->id, 'done_at' => now()]);
        Notification::make()->success()->title('Engångssteget är avbockat.')->send();
    }

    public function toggleGameSwitch(): void
    {
        $admin = $this->admin();
        $game = $this->getRecord();
        $game->forceFill(['releases_enabled' => ! $game->releases_enabled])->save();
        Log::notice('Släppbrytare ändrad för spel', ['user_id' => $admin->id, 'game' => $game->slug, 'releases_enabled' => $game->releases_enabled]);
        Notification::make()->success()->title($game->releases_enabled ? 'Släpp är påslagna för spelet.' : 'Släpp är avstängda för spelet.')->send();
    }

    // Global nödbroms utöver miljövariabeln RELEASES_ENABLED: stänger släpp för alla spel.
    // Envägs: varje spel slås på igen för sig med toggleGameSwitch.
    public function disableAllGames(): void
    {
        $this->admin();
        $count = Game::query()->update(['releases_enabled' => false]);
        // Nödbromsen ska gå att spåra i efterhand.
        Log::notice('Släpp avstängda för alla spel', ['user_id' => auth()->id(), 'games' => $count]);
        Notification::make()->success()->title('Släpp är avstängda för alla spel.')->send();
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
