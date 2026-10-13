<?php

namespace Tests\Feature\Mcp;

use App\Services\IntegrationGuide;
use Laravel\Sanctum\PersonalAccessToken;

class IntegrationGuideTest extends McpTestCase
{
    public function test_manual_has_no_token_and_creates_none(): void
    {
        $game = $this->game();

        $manual = IntegrationGuide::manual($game, IntegrationGuide::defaultFeatures($game));

        $this->assertStringContainsString('ordjakt', $manual);
        $this->assertStringNotContainsString('Dev token', $manual);
        $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{20,}/', $manual);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_tool_returns_manual_and_respects_features(): void
    {
        $user = $this->staffMember($this->game());

        $all = $this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt']);
        $auth = $this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt', 'features' => ['auth']]);

        $this->assertTrue($all['ok']);
        $this->assertStringContainsString('## Cloud Saves', $all['text']);
        $this->assertTrue($auth['ok']);
        $this->assertStringContainsString('## Authentication', $auth['text']);
        $this->assertStringNotContainsString('## Cloud Saves', $auth['text']);
    }

    public function test_unknown_feature_is_a_validation_error(): void
    {
        $user = $this->staffMember($this->game());

        $this->assertFalse($this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt', 'features' => ['bitcoin']])['ok']);
    }

    // Invarianttestet kör bara standardfunktionerna. Här tas alla med,
    // inklusive purchases som nämner RevenueCat, mot settings med hemligheter.
    public function test_manual_with_every_feature_never_contains_settings_secrets(): void
    {
        $secret = 'SECRET-'.bin2hex(random_bytes(8));
        $game = $this->game('ordjakt', ['settings' => [
            'anti_cheat_preset' => 'time_ms',
            'anti_cheat' => ['min_score' => 5000, 'max_score' => 90000, 'require_proof' => true, 'proof_algorithm' => 'fnv1a'],
            'revenuecat' => ['webhook_secret' => $secret, 'ios_public_key' => $secret],
            'anthropic' => ['api_key' => $secret],
            'elevenlabs' => ['api_key' => $secret],
            'app_store_connect' => ['private_key' => $secret],
            'google_play' => ['service_account' => $secret],
        ]]);
        $game->leaderboardTypes()->create(['slug' => 'time', 'name' => 'Time', 'sort_direction' => 'asc', 'score_label' => 'ms', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true]);

        $manual = IntegrationGuide::manual($game, array_keys(IntegrationGuide::FEATURES));

        $this->assertStringContainsString('Score range: `5000` – `90000`', $manual);
        $this->assertStringNotContainsString($secret, $manual);
    }
}
