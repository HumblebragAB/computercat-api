<?php

namespace Tests\Feature\Api\V1;

use App\Models\DailyContentPool;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyContentTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private DailyContentPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
            'settings' => [],
        ]);

        $this->pool = DailyContentPool::create([
            'game_id' => $this->game->id,
            'pool_key' => 'daily-puzzle',
            'content' => ['puzzle-a', 'puzzle-b', 'puzzle-c', 'puzzle-d', 'puzzle-e'],
            'is_active' => true,
        ]);
    }

    public function test_get_today_content_returns_deterministic_item(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle');

        $response->assertOk()
            ->assertJsonStructure(['data', 'date', 'pool_key'])
            ->assertJsonPath('pool_key', 'daily-puzzle')
            ->assertJsonPath('date', now()->toDateString());

        // Same request should return same content (deterministic)
        $response2 = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle');
        $this->assertEquals($response->json('data'), $response2->json('data'));
    }

    public function test_get_past_date_content_returns_item(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle/2025-06-15');

        $response->assertOk()
            ->assertJsonPath('date', '2025-06-15');
    }

    public function test_get_future_date_content_is_forbidden(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $futureDate = now()->addDay()->toDateString();
        $response = $this->getJson("/api/v1/games/tocco/daily/daily-puzzle/{$futureDate}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Cannot request future dates.');
    }

    public function test_nonexistent_pool_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/games/tocco/daily/nonexistent');

        $response->assertStatus(404);
    }

    public function test_inactive_pool_returns_404(): void
    {
        $this->pool->update(['is_active' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle');

        $response->assertStatus(404);
    }

    public function test_pool_with_future_starts_at_returns_404(): void
    {
        $this->pool->update(['starts_at' => now()->addWeek()]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/games/tocco/daily/daily-puzzle');

        $response->assertStatus(401);
    }

    // ── Random items (/pools/{poolKey}/items) ─────────────────────────────

    private function randomPool(array $content, array $attrs = []): DailyContentPool
    {
        return DailyContentPool::create(array_merge([
            'game_id' => $this->game->id,
            'pool_key' => 'questions-sv',
            'content' => $content,
            'is_active' => true,
            'random_access' => true,
        ], $attrs));
    }

    private function questions(int $n): array
    {
        return array_map(fn ($i) => ['id' => "q{$i}", 'question' => "Fråga {$i}", 'lang' => 'sv'], range(1, $n));
    }

    public function test_items_returns_requested_count_of_unique_pool_items(): void
    {
        $content = $this->questions(10);
        $this->randomPool($content);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/games/tocco/pools/questions-sv/items?count=4');

        $response->assertOk()
            ->assertJsonPath('pool_key', 'questions-sv')
            ->assertJsonCount(4, 'data');
        $items = $response->json('data');
        $this->assertSame(4, count(array_unique(array_column($items, 'id'))));
        foreach ($items as $item) {
            $this->assertContains($item, $content);
        }
    }

    public function test_items_defaults_to_20_and_caps_at_pool_size(): void
    {
        $this->randomPool($this->questions(30));
        $this->randomPool($this->questions(3), ['pool_key' => 'small']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/games/tocco/pools/questions-sv/items')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/v1/games/tocco/pools/small/items?count=50')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_items_requires_random_access(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Den dagliga poolen från setUp har inte valt in: hela innehållet får inte gå att läsa ut.
        $this->getJson('/api/v1/games/tocco/pools/daily-puzzle/items?count=100')->assertStatus(404);
    }

    public function test_items_inactive_or_not_started_pool_returns_404(): void
    {
        $this->randomPool($this->questions(5), ['is_active' => false]);
        $this->randomPool($this->questions(5), ['pool_key' => 'later', 'starts_at' => now()->addWeek()]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/games/tocco/pools/questions-sv/items')->assertStatus(404);
        $this->getJson('/api/v1/games/tocco/pools/later/items')->assertStatus(404);
    }

    public function test_items_pool_from_other_game_returns_404(): void
    {
        $other = Game::create(['slug' => 'tells', 'name' => 'Tells', 'is_active' => true, 'settings' => []]);
        $this->randomPool($this->questions(5), ['game_id' => $other->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/games/tocco/pools/questions-sv/items')->assertStatus(404);
        $this->getJson('/api/v1/games/tells/pools/questions-sv/items')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_items_empty_or_non_list_content_returns_empty_array(): void
    {
        $this->randomPool([]);
        $this->randomPool(['a' => 1, 'b' => 2], ['pool_key' => 'object']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/games/tocco/pools/questions-sv/items')->assertOk()->assertExactJson(['data' => [], 'pool_key' => 'questions-sv']);
        $this->getJson('/api/v1/games/tocco/pools/object/items')->assertOk()->assertJsonPath('data', []);
    }

    public function test_items_rejects_invalid_count(): void
    {
        $this->randomPool($this->questions(5));
        Sanctum::actingAs(User::factory()->create());

        foreach (['0', '101', 'abc', '-1'] as $count) {
            $this->getJson("/api/v1/games/tocco/pools/questions-sv/items?count={$count}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('count');
        }
    }

    public function test_items_unauthenticated_returns_401(): void
    {
        $this->randomPool($this->questions(5));

        $this->getJson('/api/v1/games/tocco/pools/questions-sv/items')->assertStatus(401);
    }
}
