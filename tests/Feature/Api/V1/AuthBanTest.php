<?php

namespace Tests\Feature\Api\V1;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

// En bannlysning ska gälla direkt: befintliga token raderas när flaggan slås
// på, och guarden släpper inte in en bannad användare även om ett token
// skulle finnas kvar (till exempel efter en massuppdatering utan modellhändelser).
class AuthBanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function getMe(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/auth/me', $this->bearer($token));
    }

    private function getSaves(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/games/tocco/saves', $this->bearer($token));
    }

    /** @return array{0: User, 1: string} */
    private function anonymousWithToken(): array
    {
        $token = $this->postJson('/api/v1/auth/anonymous')->assertCreated()->json('token');

        return [PersonalAccessToken::findToken($token)->tokenable, $token];
    }

    public function test_ban_via_model_update_deletes_tokens_and_blocks_api(): void
    {
        [$user, $token] = $this->anonymousWithToken();
        $this->getMe($token)->assertOk();
        $this->getSaves($token)->assertOk();

        $user->update(['is_banned' => true]);

        $this->assertSame(0, $user->tokens()->count());
        $this->getMe($token)->assertUnauthorized();
        $this->getSaves($token)->assertUnauthorized();
    }

    public function test_unban_does_not_resurrect_tokens(): void
    {
        [$user, $token] = $this->anonymousWithToken();

        $user->update(['is_banned' => true]);
        $user->update(['is_banned' => false]);

        $this->assertSame(0, $user->tokens()->count());
        $this->getMe($token)->assertUnauthorized();
    }

    public function test_other_updates_keep_tokens(): void
    {
        [$user, $token] = $this->anonymousWithToken();

        $user->update(['display_name' => 'Nytt']);

        $this->assertSame(1, $user->tokens()->count());
        $this->getMe($token)->assertOk();
    }

    // Massuppdateringar går förbi modellhändelserna; guarden stoppar ändå.
    public function test_guard_rejects_banned_user_whose_token_survived(): void
    {
        [$user, $token] = $this->anonymousWithToken();

        User::whereKey($user->id)->update(['is_banned' => true]);

        $this->assertSame(1, $user->tokens()->count());
        $this->getMe($token)->assertUnauthorized();
        $this->getSaves($token)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/refresh', [], $this->bearer($token))->assertUnauthorized();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_ban_via_filament_toggle_deletes_tokens(): void
    {
        [$user, $token] = $this->anonymousWithToken();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['is_banned' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->is_banned);
        $this->assertSame(0, $user->tokens()->count());
        $this->getMe($token)->assertUnauthorized();
    }
}
