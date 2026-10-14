<?php

namespace Tests\Feature\Mcp;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_reaches_own_games_including_inactive_and_nothing_else(): void
    {
        $mine = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => false, 'settings' => []]);
        Game::firstOrCreate(['slug' => 'glosis'], ['name' => 'G', 'is_active' => true, 'settings' => []]); // finns redan via migration
        $user = User::factory()->staff()->create();
        $mine->members()->attach($user, ['role' => 'owner']);

        $this->assertTrue(GameAccess::find($user, 'mine')?->is($mine));
        $this->assertNull(GameAccess::find($user, 'glosis'));
        $this->assertNull(GameAccess::find($user, 'nope'));
        $this->assertSame(['mine'], GameAccess::visible($user)->pluck('slug')->all());
    }

    public function test_platform_admin_reaches_all_games(): void
    {
        Game::firstOrCreate(['slug' => 'glosis'], ['name' => 'G', 'is_active' => true, 'settings' => []]); // finns redan via migration
        $admin = User::factory()->staff(admin: true)->create();

        $this->assertNotNull(GameAccess::find($admin, 'glosis'));
    }

    public function test_banned_member_reaches_nothing(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $user = User::factory()->staff()->create(['is_banned' => true]);
        $game->members()->attach($user, ['role' => 'member']);

        $this->assertNull(GameAccess::find($user, 'mine'));
    }

    public function test_members_tab_only_offers_staff_accounts(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $staff = User::factory()->staff()->create(['email' => 'mattias@humblebrag.se']);
        $player = User::factory()->create(['email' => 'spelare@x.se']);
        $this->actingAs(User::factory()->staff(admin: true)->create());

        \Livewire\Livewire::test(\App\Filament\Resources\GameResource\RelationManagers\MembersRelationManager::class, [
            'ownerRecord' => $game, 'pageClass' => \App\Filament\Resources\GameResource\Pages\EditGame::class,
        ])->callTableAction(\Filament\Tables\Actions\AttachAction::class, data: ['recordId' => $player->id, 'role' => 'member'])
            ->assertHasTableActionErrors();

        $this->assertFalse($game->members()->whereKey($player->id)->exists());
        $this->assertFalse($game->members()->whereKey($staff->id)->exists());
    }

    public function test_members_table_shows_pivot_role(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $staff = User::factory()->staff()->create(['email' => 'mattias@humblebrag.se']);
        $game->members()->attach($staff, ['role' => 'owner']);
        $this->actingAs(User::factory()->staff(admin: true)->create());

        \Livewire\Livewire::test(\App\Filament\Resources\GameResource\RelationManagers\MembersRelationManager::class, [
            'ownerRecord' => $game, 'pageClass' => \App\Filament\Resources\GameResource\Pages\EditGame::class,
        ])->assertSee('mattias@humblebrag.se')->assertSee('owner');
    }

    public function test_is_owner_follows_pivot_role_or_platform_admin(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $owner = User::factory()->staff()->create();
        $member = User::factory()->staff()->create();
        $bannedOwner = User::factory()->staff()->create(['is_banned' => true]);
        $admin = User::factory()->staff(admin: true)->create();
        $game->members()->attach($owner, ['role' => 'owner']);
        $game->members()->attach($member, ['role' => 'member']);
        $game->members()->attach($bannedOwner, ['role' => 'owner']);

        $this->assertTrue(GameAccess::isOwner($owner, $game));
        $this->assertFalse(GameAccess::isOwner($member, $game));
        $this->assertFalse(GameAccess::isOwner($bannedOwner, $game));
        $this->assertTrue(GameAccess::isOwner($admin, $game));
    }
}
