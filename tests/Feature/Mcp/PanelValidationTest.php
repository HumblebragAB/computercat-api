<?php

namespace Tests\Feature\Mcp;

use App\Filament\Resources\AchievementDefinitionResource\Pages\CreateAchievementDefinition;
use App\Filament\Resources\AchievementDefinitionResource\Pages\EditAchievementDefinition;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\PurchaseResource\Pages\EditPurchase;
use App\Models\AchievementDefinition;
use App\Models\Purchase;
use App\Models\User;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Actions\CreateAction;
use Livewire\Livewire;

// Panelens formulär ska validera på servern som MCP gör: Select validerar inte
// mot sina options, och toppnivåresurser saknar ägarpost att hämta spelet från.
class PanelValidationTest extends McpTestCase
{
    private function achievement(array $attrs = []): AchievementDefinition
    {
        return AchievementDefinition::create(array_merge([
            'game_id' => $this->game()->id, 'slug' => 'first-win', 'name' => 'First', 'description' => 'D',
            'icon' => '*', 'sort_order' => 0, 'is_secret' => false, 'is_active' => true,
        ], $attrs));
    }

    private function achievementForm(array $override = []): array
    {
        return array_merge([
            'game_id' => $this->game()->id, 'slug' => 'first-win', 'name' => 'First', 'description' => 'D',
            'icon' => '*', 'sort_order' => 0, 'is_secret' => false, 'is_active' => true,
        ], $override);
    }

    public function test_top_level_achievement_resource_rejects_duplicate_slug_with_validation_error(): void
    {
        $this->achievement();
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(CreateAchievementDefinition::class)
            ->fillForm($this->achievementForm())
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertSame(1, AchievementDefinition::count());
    }

    public function test_top_level_achievement_resource_rejects_bad_slug_and_sort_order(): void
    {
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(CreateAchievementDefinition::class)
            ->fillForm($this->achievementForm(['slug' => 'Bad Slug', 'sort_order' => 100001]))
            ->call('create')
            ->assertHasFormErrors(['slug', 'sort_order']);

        $this->assertSame(0, AchievementDefinition::count());
    }

    public function test_top_level_achievement_resource_without_game_gives_validation_error(): void
    {
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(CreateAchievementDefinition::class)
            ->fillForm($this->achievementForm(['game_id' => null]))
            ->call('create')
            ->assertHasFormErrors(['game_id']);

        // Ett id som inte finns ska inte nå databasens främmande nyckel.
        Livewire::test(CreateAchievementDefinition::class)
            ->fillForm($this->achievementForm(['game_id' => 999999]))
            ->call('create')
            ->assertHasFormErrors(['game_id']);

        $this->assertSame(0, AchievementDefinition::count());
    }

    /** Kontroll av testet själv: samma slug i ett annat spel är tillåten. */
    public function test_top_level_achievement_resource_accepts_same_slug_in_other_game(): void
    {
        $this->achievement();
        $other = $this->game('glosis');
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(CreateAchievementDefinition::class)
            ->fillForm($this->achievementForm(['game_id' => $other->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, AchievementDefinition::count());
    }

    public function test_top_level_achievement_resource_keeps_legacy_slug_editable(): void
    {
        $legacy = $this->achievement(['slug' => 'Legacy Slug']);
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(EditAchievementDefinition::class, ['record' => $legacy->getRouteKey()])
            ->fillForm(['name' => 'Nytt namn'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nytt namn', $legacy->fresh()->name);
        $this->assertSame('Legacy Slug', $legacy->fresh()->slug);
    }

    public function test_members_role_outside_options_is_rejected(): void
    {
        $game = $this->game();
        $this->actingAs($this->staffMember($game));
        $other = User::factory()->staff()->create();

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(AttachAction::class, data: ['recordId' => $other->id, 'role' => 'superadmin'])
            ->assertHasTableActionErrors(['role']);

        $this->assertFalse($game->members()->whereKey($other->id)->exists());
    }

    /** Kontroll av testet själv: en giltig roll går igenom. */
    public function test_members_valid_role_is_accepted(): void
    {
        $game = $this->game();
        $this->actingAs($this->staffMember($game));
        $other = User::factory()->staff()->create();

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(AttachAction::class, data: ['recordId' => $other->id, 'role' => 'owner'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('owner', $game->members()->whereKey($other->id)->first()->pivot->role);
    }

    public function test_product_type_and_grant_type_outside_options_are_rejected(): void
    {
        $game = $this->game();
        $this->actingAs($this->staffMember($game));

        Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: [
                'product_id' => 'p1', 'reference_name' => 'P1', 'product_type' => 'bogus', 'is_active' => true,
                'grant_type' => 'bogus', 'price' => 10, 'currency' => 'SEK', 'display_name' => 'P1',
                'description' => 'D', 'sort_order' => 0,
            ])
            ->assertHasTableActionErrors(['product_type', 'grant_type']);

        $this->assertSame(0, $game->products()->count());
    }

    public function test_purchase_status_outside_options_is_rejected(): void
    {
        $purchase = Purchase::create([
            'user_id' => User::factory()->create()->id, 'game_id' => $this->game()->id, 'product_id' => 'p1',
            'store' => 'apple', 'transaction_id' => 't1', 'status' => 'pending', 'purchased_at' => now(),
        ]);
        $this->actingAs($this->staffMember($this->game()));

        Livewire::test(EditPurchase::class, ['record' => $purchase->getRouteKey()])
            ->fillForm(['status' => 'bogus'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_anti_cheat_preset_outside_options_is_rejected(): void
    {
        $game = $this->game();
        $this->actingAs($this->staffMember($game));

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['settings.anti_cheat_preset' => 'bogus'])
            ->call('save')
            ->assertHasFormErrors(['settings.anti_cheat_preset']);
    }

    /** Samma tak som set_allowed_origins. */
    public function test_allowed_origins_are_capped_at_twenty(): void
    {
        $game = $this->game();
        $this->actingAs($this->staffMember($game));
        $origins = array_map(fn ($i) => "https://app{$i}.example.se", range(1, 21));

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['allowed_origins' => $origins])
            ->call('save')
            ->assertHasFormErrors(['allowed_origins']);

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->fillForm(['allowed_origins' => array_slice($origins, 0, 20)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(20, $game->fresh()->allowed_origins);
    }
}
