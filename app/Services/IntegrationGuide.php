<?php

namespace App\Services;

use App\Filament\Resources\GameResource;
use App\Models\Game;

// Dev Kit-manualen och uppsättningsöversikten. Används av Dev Kit-sidan i
// panelen och av MCP-verktyget get_integration_guide. Manualen innehåller
// aldrig någon token: den beskriver hur appen själv skapar en anonym användare.
//
// Ur games.settings läses bara anti_cheat och anti_cheat_preset (publika
// regler som spelet ändå måste följa). Hemligheter i settings (RevenueCat
// webhook_secret, API-nycklar, butikernas nycklar) får aldrig skrivas ut här.
class IntegrationGuide
{
    /** Funktionsnyckel => etikett, i den ordning de visas. */
    public const FEATURES = [
        'auth' => 'Authentication',
        'leaderboards' => 'Leaderboards',
        'achievements' => 'Achievements',
        'saves' => 'Cloud Saves',
        'daily_content' => 'Daily Content',
        'streaks' => 'Server-side Streaks',
        'remote_config' => 'Remote Config / Feature Flags',
        'player_stats' => 'Player Stats',
        'events' => 'Scheduled Events',
        'purchases' => 'Purchases & Ownership',
    ];

    private const RELATIONS = ['leaderboardTypes', 'achievementDefinitions', 'dailyContentPools', 'remoteConfigs', 'gameEvents'];

    /**
     * Funktionerna spelet har konfigurerat (plus de som alltid gäller).
     *
     * @return list<string>
     */
    public static function defaultFeatures(Game $game): array
    {
        $game->loadMissing(self::RELATIONS);

        $features = ['auth'];
        if ($game->leaderboardTypes->count()) {
            $features[] = 'leaderboards';
        }
        if ($game->achievementDefinitions->count()) {
            $features[] = 'achievements';
        }
        $features[] = 'saves';
        if ($game->dailyContentPools->count()) {
            $features[] = 'daily_content';
        }
        $features[] = 'streaks';
        if ($game->remoteConfigs->count()) {
            $features[] = 'remote_config';
        }
        $features[] = 'player_stats';
        if ($game->gameEvents->count()) {
            $features[] = 'events';
        }

        return $features;
    }

    public static function setupGuide(Game $game): string
    {
        $game->loadMissing(self::RELATIONS);
        $lines = [];

        $lines[] = '## 1. Game Configuration';
        $lines[] = '- **Name:** '.self::inline($game->name);
        $lines[] = '- **Slug:** `'.self::code($game->slug).'`';
        $lines[] = '- **Status:** '.($game->is_active ? 'Active' : 'Inactive');
        $lines[] = '';

        if ($game->leaderboardTypes->count()) {
            $lines[] = "## 2. Leaderboards ({$game->leaderboardTypes->count()})";
            foreach ($game->leaderboardTypes as $lb) {
                $lines[] = '- **'.self::inline($lb->name).'** (`'.self::code($lb->slug).'`) — '.self::inline($lb->period).', sort: '.self::inline($lb->sort_direction).', label: "'.self::inline($lb->score_label).'"';
            }
            $lines[] = '';
        }

        if ($game->achievementDefinitions->count()) {
            $lines[] = "## 3. Achievements ({$game->achievementDefinitions->count()})";
            $lines[] = 'Manage via the Edit page > Achievements tab.';
            $lines[] = '';
        }

        if ($game->dailyContentPools->count()) {
            $lines[] = "## Daily Content Pools ({$game->dailyContentPools->count()})";
            foreach ($game->dailyContentPools as $pool) {
                $count = is_array($pool->content) ? count($pool->content) : 0;
                $lines[] = '- **'.self::inline($pool->pool_key)."** — {$count} items".($pool->is_active ? '' : ' (inactive)');
            }
            $lines[] = '';
        }

        if ($game->remoteConfigs->where('is_active', true)->count()) {
            $lines[] = "## Remote Config ({$game->remoteConfigs->where('is_active', true)->count()} active)";
            $lines[] = 'Managed via Edit page > Remote Configs tab.';
            $lines[] = '';
        }

        if ($game->gameEvents->count()) {
            $lines[] = "## Events ({$game->gameEvents->count()})";
            foreach ($game->gameEvents as $event) {
                $status = now()->between($event->starts_at, $event->ends_at) ? 'active' : 'scheduled';
                $lines[] = '- **'.self::inline($event->name).'** (`'.self::code($event->slug).'`) — '.self::inline($event->event_type).", {$status}";
            }
            $lines[] = '';
        }

        $lines[] = '## Next Step';
        $lines[] = "Copy the **AI Agent Manual** below and paste it into your AI coding assistant's context.";

        return implode("\n", $lines);
    }

    /** @param  array<string>  $features  nycklar ur FEATURES */
    public static function manual(Game $game, array $features): string
    {
        $game->loadMissing(self::RELATIONS);
        $baseUrl = rtrim(config('app.url'), '/').'/api/v1';
        $lines = [];

        $lines[] = '# Computer Cat API — Integration Manual';
        $lines[] = '';
        $lines[] = 'You are integrating "'.self::inline($game->name).'" with the Computer Cat backend.';
        $lines[] = '';
        $lines[] = '## Credentials';
        $lines[] = '';
        $lines[] = '| Key | Value |';
        $lines[] = '|-----|-------|';
        $lines[] = "| Base URL | `{$baseUrl}` |";
        $lines[] = '| Game slug | `'.self::code($game->slug).'` |';
        $lines[] = '';
        $lines[] = 'For testing, create an anonymous user exactly as the app does (see Authentication). There is no shared dev token.';
        $lines[] = '';
        $lines[] = 'All authenticated requests need the header:';
        $lines[] = '```';
        $lines[] = 'Authorization: Bearer <token>';
        $lines[] = '```';
        $lines[] = '';

        $lines[] = '## Architecture';
        $lines[] = '';
        $lines[] = 'Build **offline-first**:';
        $lines[] = '- Game logic is 100% client-side. API is for sync only.';
        $lines[] = '- Store everything in local storage. Push to cloud in background.';
        $lines[] = '- Game must work perfectly without internet.';
        $lines[] = '- On launch: pull cloud → merge with local → push merged state.';
        $lines[] = '- **Tokens expire after 7 days.** On 401, create a new anonymous user and re-sync.';
        $lines[] = '';

        if (in_array('auth', $features)) {
            $lines[] = '## Authentication';
            $lines[] = '';
            $lines[] = 'Use anonymous-first auth. Create a user silently on first launch.';
            $lines[] = '';
            $lines[] = '### Create anonymous user';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/auth/anonymous";
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"display_name": "Player"}';
            $lines[] = '```';
            $lines[] = 'Response: `{"token": "1|abc...", "user": {"id": 1, "display_name": "Player", "is_anonymous": true}}`';
            $lines[] = '';
            $lines[] = 'Store token persistently. Use for all subsequent requests.';
            $lines[] = '';
            $lines[] = '### Verify on launch';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/auth/me";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'If 401 → create new anonymous user.';
            $lines[] = '';
            $lines[] = '### Upgrade to email (optional)';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/auth/register";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"name": "Name", "email": "a@b.com", "password": "secret", "password_confirmation": "secret"}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Update display name';
            $lines[] = '```';
            $lines[] = "PATCH {$baseUrl}/auth/me";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"display_name": "NewName"}';
            $lines[] = '```';
            $lines[] = '**Display name rules:** max 30 characters, only letters, numbers, spaces, and `- _ . !`. HTML tags are stripped.';
            $lines[] = '';
        }

        if (in_array('leaderboards', $features) && $game->leaderboardTypes->count()) {
            $lines[] = '## Leaderboards';
            $lines[] = '';
            $lines[] = '### Configured types';
            foreach ($game->leaderboardTypes as $lb) {
                $dir = $lb->sort_direction === 'asc' ? 'lower is better' : 'higher is better';
                $lines[] = '- `'.self::code($lb->slug).'` — '.self::inline($lb->name).', '.self::inline($lb->period).", {$dir}, label: \"".self::inline($lb->score_label).'"';
            }
            $lines[] = '';

            $ex = $game->leaderboardTypes->first();
            $lines[] = '### Submit score';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/games/{$game->slug}/leaderboards/{$ex->slug}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"score": 4250, "metadata": {"level": "6x6"}}';
            $lines[] = '```';
            $lines[] = '- `score`: integer (e.g. milliseconds for time)';
            $lines[] = '- `metadata`: optional context JSON — include a `proof` object for anti-cheat validation';
            $lines[] = '- Best score per user per period is kept automatically';
            $lines[] = '- Server validates score against game-specific bounds (configured in game settings)';
            $lines[] = '';
            // Anti-cheat documentation based on configured preset
            $acConfig = $game->settings['anti_cheat'] ?? [];
            $acConfig = is_array($acConfig) ? $acConfig : [];
            $presetKey = $game->settings['anti_cheat_preset'] ?? 'none';
            $presetKey = is_string($presetKey) ? $presetKey : 'invalid';
            $preset = GameResource::ANTI_CHEAT_PRESETS[$presetKey] ?? null;

            if ($presetKey !== 'none') {
                $lines[] = '### Anti-cheat';
                $lines[] = '';
                if ($preset) {
                    $lines[] = "**Type:** {$preset['label']}";
                    $lines[] = '';
                    $lines[] = $preset['description'];
                    $lines[] = '';
                }
                $minScore = self::setting($acConfig['min_score'] ?? 0, code: true);
                $maxScore = self::setting($acConfig['max_score'] ?? 86400, code: true);
                $lines[] = "- Score range: `{$minScore}` – `{$maxScore}`";

                $requireProof = $acConfig['require_proof'] ?? false;
                $proofAlgo = $acConfig['proof_algorithm'] ?? null;
                $minMoves = self::setting($acConfig['min_moves'] ?? 1);

                if ($requireProof || $proofAlgo) {
                    $lines[] = '- Proof-of-play: '.($requireProof ? '**required**' : 'optional but validated if present');
                    $lines[] = '';
                    $lines[] = 'Include a `proof` object in `metadata`:';
                    $lines[] = '```json';
                    $lines[] = '{"score": 45000, "metadata": {"proof": {"pid": "round-123", "mc": 18, "gh": "a1b2c3", "t": 45000, "sig": "x9y8z7"}}}';
                    $lines[] = '```';
                    $lines[] = '';
                    $lines[] = '| Field | Description |';
                    $lines[] = '|-------|-------------|';
                    $lines[] = '| `pid` | Round/puzzle identifier |';
                    $lines[] = "| `mc` | Move/action count (min: {$minMoves}) |";
                    $lines[] = '| `gh` | Hash of final game state |';
                    $lines[] = '| `t` | Elapsed time |';
                    $lines[] = '| `sig` | Signature over `pid\\|mc\\|gh\\|t` |';
                    $lines[] = '';
                    if ($proofAlgo === 'fnv1a') {
                        $lines[] = '**Signature algorithm: FNV-1a**';
                        $lines[] = '```';
                        $lines[] = 'payload = `${pid}|${mc}|${gh}|${t}`';
                        $lines[] = 'h = 0x811c9dc5  // FNV offset basis';
                        $lines[] = 'for each byte in payload:';
                        $lines[] = '    h = h XOR byte';
                        $lines[] = '    h = h * 0x01000193  // FNV prime (32-bit multiply)';
                        $lines[] = 'sig = unsigned_32bit(h).toString(36)';
                        $lines[] = '```';
                    }
                }
                $lines[] = '';
            }
            $lines[] = '### Get leaderboard';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/leaderboards/{$ex->slug}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Get my rank';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/leaderboards/{$ex->slug}/me";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = 'Submit in background after completing a round. Queue if offline.';
            $lines[] = '';
        }

        if (in_array('achievements', $features) && $game->achievementDefinitions->count()) {
            $lines[] = '## Achievements';
            $lines[] = '';
            $lines[] = 'Evaluated **client-side**. Server stores unlock state for cross-device sync.';
            $lines[] = '';
            $lines[] = '### Definitions';
            $lines[] = '| Slug | Name | Description | Icon |';
            $lines[] = '|------|------|-------------|------|';
            foreach ($game->achievementDefinitions->sortBy('sort_order') as $a) {
                $s = $a->is_secret ? ' (secret)' : '';
                $lines[] = '| `'.self::code($a->slug).'` | '.self::inline($a->name).$s.' | '.self::inline($a->description).' | '.self::inline($a->icon).' |';
            }
            $lines[] = '';
            $lines[] = '### Report unlocks';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/games/{$game->slug}/achievements";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"slugs": ["first-win", "speed-demon"]}';
            $lines[] = '```';
            $lines[] = 'Idempotent — already-unlocked slugs are ignored. Fire-and-forget.';
            $lines[] = '';
            $lines[] = '### Get my achievements';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/achievements/me";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Pattern';
            $lines[] = '1. Define unlock conditions in client code';
            $lines[] = '2. Check conditions after each game action';
            $lines[] = '3. New unlocks → store locally AND POST to server';
            $lines[] = '4. On sync: union of server + local unlocks';
            $lines[] = '';
        }

        if (in_array('saves', $features)) {
            $lines[] = '## Cloud Saves';
            $lines[] = '';
            $lines[] = 'Opaque JSON blob. Client owns the schema. Optimistic locking via `version`. Max payload: **512 KB**.';
            $lines[] = '';
            $lines[] = '**Important:** The server strips any `ownership` key from save data on upload. Ownership (purchased content) is server-authoritative — do NOT store it in cloud saves.';
            $lines[] = '';
            $lines[] = '### Save';
            $lines[] = '```';
            $lines[] = "PUT {$baseUrl}/games/{$game->slug}/saves/main";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"version": 1, "data": {"progress": {}, "settings": {}}}';
            $lines[] = '```';
            $lines[] = '- `version` must match server. Mismatch → 409 Conflict.';
            $lines[] = '- Returns new version on success.';
            $lines[] = '- `main` is default key. Use other keys for save slots.';
            $lines[] = '';
            $lines[] = '### Load';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/saves/main";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Sync strategy';
            $lines[] = '1. Launch → GET /saves/main';
            $lines[] = '2. Merge cloud + local (best scores, union achievements, latest settings)';
            $lines[] = '3. PUT merged state back';
            $lines[] = '4. During play: debounce pushes (3s after last change)';
            $lines[] = '5. On 409: pull, re-merge, retry';
            $lines[] = '';
        }

        if (in_array('purchases', $features)) {
            $lines[] = '## Ownership';
            $lines[] = '';
            $lines[] = 'Server-authoritative check of what the user owns based on verified purchases.';
            $lines[] = '';
            $lines[] = '### Get ownership';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/ownership";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": {"owned_packs": ["6x6-medium"], "owned_themes": ["retro", "spring"], "is_supporter": false}}';
            $lines[] = '```';
            $lines[] = 'Call on app boot after auth. Use this as the source of truth for purchased content. Gameplay-earned rewards (unlocked through play) can be tracked locally and via cloud saves.';
            $lines[] = '';
        }

        if (in_array('purchases', $features)) {
            $lines[] = '## Purchases (RevenueCat)';
            $lines[] = '';
            $lines[] = 'Purchases are handled via **RevenueCat**. The client uses the RevenueCat SDK to show paywalls and complete purchases; the server receives webhooks from RevenueCat to keep the `purchases` table in sync.';
            $lines[] = '';
            $lines[] = '### Client setup';
            $lines[] = '```bash';
            $lines[] = 'npm install @revenuecat/purchases-capacitor';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = "Initialize with the game's public API key (from `games.settings.revenuecat.ios_public_key`):";
            $lines[] = '```js';
            $lines[] = "import { Purchases } from '@revenuecat/purchases-capacitor';";
            $lines[] = '';
            $lines[] = '// On app boot, after auth';
            $lines[] = 'await Purchases.configure({';
            $lines[] = '  apiKey: PUBLIC_API_KEY,';
            $lines[] = '  appUserID: String(user.id),  // IMPORTANT: use our numeric user ID';
            $lines[] = '});';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = "**Critical:** Set `appUserID` to the user's numeric ID from `/auth/me`. RevenueCat uses this to identify users across devices, and webhooks send it as `app_user_id` so the server can match events to users.";
            $lines[] = '';
            $lines[] = '### Purchase a product';
            $lines[] = '```js';
            $lines[] = 'const { customerInfo } = await Purchases.purchaseStoreProduct({';
            $lines[] = "  product: { identifier: 'your_product_id' },";
            $lines[] = '});';
            $lines[] = '// RevenueCat verifies the receipt automatically and';
            $lines[] = '// triggers a webhook to our server. No manual API call needed.';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Restore purchases';
            $lines[] = '```js';
            $lines[] = 'await Purchases.restorePurchases();';
            $lines[] = '// Then re-fetch ownership from our server:';
            $lines[] = "const ownership = await fetch('{$baseUrl}/games/{$game->slug}/ownership');";
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Server-side (no client action needed)';
            $lines[] = 'When a purchase happens, RevenueCat sends a webhook to:';
            $lines[] = '```';
            $lines[] = rtrim(config('app.url'), '/').'/api/v1/webhooks/revenuecat/'.self::code($game->slug);
            $lines[] = '```';
            $lines[] = 'The server verifies the signature, upserts the `Purchase` record, and the `/ownership` endpoint immediately reflects the new purchase. Refunds, cancellations, and expirations are handled automatically.';
            $lines[] = '';
            $lines[] = '### Pattern';
            $lines[] = '1. On app boot: call `Purchases.configure()` with user ID';
            $lines[] = '2. On paywall: show offerings from `Purchases.getOfferings()`';
            $lines[] = '3. On purchase tap: `Purchases.purchaseStoreProduct()`';
            $lines[] = '4. After success: re-fetch `/ownership` to get updated content access';
            $lines[] = "5. Never trust RevenueCat's local entitlements — always use our `/ownership` as source of truth";
            $lines[] = '';
        }

        // ── Daily Content ─────────────────────────────────────────────────
        if (in_array('daily_content', $features)) {
            $lines[] = '## Daily Content';
            $lines[] = '';
            $lines[] = "Server picks today's content deterministically from a pool. No peeking at future content.";
            $lines[] = '';
            $lines[] = "### Get today's content";
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/daily/{poolKey}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": <item>, "date": "2026-04-02", "pool_key": "puzzles"}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Get content for a past date';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/daily/{poolKey}/{date}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '- `date` format: `YYYY-MM-DD`. Only today and past dates are allowed.';
            $lines[] = '- Selection is deterministic: same date always returns same content.';
            $lines[] = '- Content pools are managed in the admin panel (Edit Game → Daily Content Pools tab).';
            $lines[] = '';

            if ($game->dailyContentPools->count()) {
                $lines[] = '### Configured pools';
                foreach ($game->dailyContentPools as $pool) {
                    $count = is_array($pool->content) ? count($pool->content) : 0;
                    $random = $pool->random_access ? ' (random access)' : '';
                    $lines[] = '- `'.self::code($pool->pool_key)."` — {$count} items{$random}";
                }
                $lines[] = '';
            }

            $lines[] = '### Pattern';
            $lines[] = "1. On app launch, fetch today's content: `GET /daily/{poolKey}`";
            $lines[] = "2. Cache locally by date — content won't change for a given date";
            $lines[] = '3. If offline, fall back to cached content or a local pool';
            $lines[] = '';
            $lines[] = '### Random items from a pool';
            $lines[] = 'For question banks and similar, where the player may see the whole pool. The pool must have **Random access** switched on, otherwise 404.';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/pools/{poolKey}/items?count=20";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": [<item>, <item>, ...], "pool_key": "questions-sv"}';
            $lines[] = '```';
            $lines[] = '- `count`: 1–100, default 20. Returns at most the pool size, distinct items, random order.';
            $lines[] = '- One pool per language is the convention (`questions-sv`, `questions-en`).';
            $lines[] = '';
        }

        // ── Streaks ──────────────────────────────────────────────────────────
        if (in_array('streaks', $features)) {
            $lines[] = '## Server-side Streaks';
            $lines[] = '';
            $lines[] = 'Server-authoritative streak tracking. Prevents client-side manipulation.';
            $lines[] = '';
            $lines[] = '### Get streak';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/streaks/{key}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": {"streak_key": "daily", "current_streak": 7, "longest_streak": 14, "last_activity_date": "2026-04-02", "freeze_balance": 2}}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Record activity';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/games/{$game->slug}/streaks/{key}/record";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Server logic:';
            $lines[] = '- **Same day** → no-op (idempotent)';
            $lines[] = '- **Consecutive day** → streak + 1';
            $lines[] = '- **Missed 1 day + has freeze** → freeze used, streak preserved';
            $lines[] = '- **Missed 2+ days** → streak resets to 1';
            $lines[] = '';
            $lines[] = 'Freeze earning is automatic: 1 freeze per N consecutive days (configurable in game settings under `streaks.{key}.freeze_earn_interval`).';
            $lines[] = '';
            $lines[] = '### Pattern';
            $lines[] = '1. After daily puzzle/round completion → `POST /streaks/daily/record`';
            $lines[] = '2. On app boot → `GET /streaks/daily` to display current streak';
            $lines[] = '3. Show freeze balance in UI so player knows their safety net';
            $lines[] = '';
        }

        // ── Remote Config ────────────────────────────────────────────────────
        if (in_array('remote_config', $features)) {
            $lines[] = '## Remote Config / Feature Flags';
            $lines[] = '';
            $lines[] = 'Key-value configuration fetched on app boot. No auth required (public endpoint for fast loading).';
            $lines[] = '';
            $lines[] = '### Fetch config';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/config";
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": {"maintenance_mode": false, "min_app_version": "1.2.0", "seasonal_theme": "summer", "new_feature_enabled": true}}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '- Values are typed: `string`, `int`, `bool`, or `json`';
            $lines[] = '- Managed in admin panel (Edit Game → Remote Configs tab)';
            $lines[] = '- No auth required — call before authentication for fast boot';
            $lines[] = '';

            if ($game->remoteConfigs->count()) {
                $lines[] = '### Current config keys';
                $lines[] = '| Key | Type | Description |';
                $lines[] = '|-----|------|-------------|';
                foreach ($game->remoteConfigs->where('is_active', true) as $rc) {
                    $lines[] = '| `'.self::code($rc->key).'` | '.self::inline($rc->value_type).' | '.self::inline($rc->description).' |';
                }
                $lines[] = '';
            }

            $lines[] = '### Pattern';
            $lines[] = '1. Fetch config on app boot (before auth, fast)';
            $lines[] = '2. Cache locally with a TTL (e.g. 1 hour)';
            $lines[] = '3. Check `maintenance_mode` before showing game UI';
            $lines[] = '4. Use feature flags to toggle new features without app update';
            $lines[] = '';
        }

        // ── Player Stats ─────────────────────────────────────────────────────
        if (in_array('player_stats', $features)) {
            $lines[] = '## Player Stats';
            $lines[] = '';
            $lines[] = 'Server-computed aggregate statistics. Read-only.';
            $lines[] = '';
            $lines[] = '### Get my stats';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/stats/me";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": {"total_games": 142, "best_scores": {"daily-time": 23400}, "achievement_count": 8, "current_streaks": {"daily": 7}, "member_since": "2026-03-15", "last_active": "2026-04-02"}}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '- Computed on the fly from leaderboard entries, achievements, and streaks';
            $lines[] = '- Use for profile screens, share cards, or onboarding personalization';
            $lines[] = '';
        }

        // ── Scheduled Events ─────────────────────────────────────────────────
        if (in_array('events', $features)) {
            $lines[] = '## Scheduled Events';
            $lines[] = '';
            $lines[] = 'Time-boxed events: seasonal challenges, weekend tournaments, limited content.';
            $lines[] = '';
            $lines[] = '### List active events';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/events";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Returns only events where `now()` is between `starts_at` and `ends_at`.';
            $lines[] = '';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": [{"slug": "spring-challenge", "name": "Spring Challenge", "event_type": "leaderboard", "starts_at": "2026-04-01T00:00:00Z", "ends_at": "2026-04-07T23:59:59Z", "settings": {"leaderboard_type": "weekly-time"}}]}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Get single event';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/{$game->slug}/events/{slug}";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Event types';
            $lines[] = '- `leaderboard` — temporary competitive leaderboard (reference a leaderboard type in `settings.leaderboard_type`)';
            $lines[] = '- `challenge` — goal-based event ("complete 10 puzzles this weekend")';
            $lines[] = '- `seasonal` — cosmetic/thematic event (holiday themes, special content)';
            $lines[] = '';
            $lines[] = '### Pattern';
            $lines[] = '1. Fetch active events on app boot → `GET /events`';
            $lines[] = '2. Show event banners/badges in the UI';
            $lines[] = '3. For leaderboard events, use the referenced leaderboard type for score submission';
            $lines[] = '4. Events are managed in the admin panel (Edit Game → Events tab)';
            $lines[] = '';
        }

        $lines[] = '## Error Handling';
        $lines[] = '';
        $lines[] = 'All errors return JSON: `{"message": "...", "errors": {...}}`';
        $lines[] = '';
        $lines[] = '| Status | Meaning | Action |';
        $lines[] = '|--------|---------|--------|';
        $lines[] = '| 401 | Token expired | Create new anonymous user |';
        $lines[] = '| 404 | Not found | — |';
        $lines[] = '| 409 | Save conflict | Pull, merge, retry |';
        $lines[] = '| 422 | Validation | Check `errors` |';
        $lines[] = '| 429 | Rate limited | Back off, retry |';
        $lines[] = '';
        $lines[] = '## Rate Limits';
        $lines[] = '- General: 60/min';
        $lines[] = '- Auth: 10/min';
        $lines[] = '- Score submit: 30/min';

        return implode("\n", $lines);
    }

    // Användarstyrda värden (namn, beskrivningar, etiketter) läses av andra
    // Claude-sessioner via get_integration_guide. Radbrytningar slås ihop och
    // markdown-tecken escapas, så att ett värde inte kan bryta sig ut ur sin
    // tabellcell eller listpunkt och bli en rubrik, länk eller instruktion.
    private static function inline(mixed $value): string
    {
        return preg_replace('/([\\\\`|\[\]<>*_#])/', '\\\\$1', self::oneLine($value));
    }

    // För värden inom `kod`: markdown tolkas inte där, men en backtick
    // avslutar spannet och | delar en tabellcell.
    private static function code(mixed $value): string
    {
        return str_replace(['`', '|'], ["'", '\\|'], self::oneLine($value));
    }

    // Anti-cheat-värden ur settings: allt som inte är skalärt visas som invalid.
    private static function setting(mixed $value, bool $code = false): string
    {
        if (! is_scalar($value)) {
            return 'invalid';
        }

        return $code ? self::code($value) : self::inline($value);
    }

    private static function oneLine(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        // Ogiltig UTF-8 ger null med /u; då tas byte-varianten.
        $collapsed = preg_replace('/[\s\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value)
            ?? preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $value);

        return trim($collapsed);
    }
}
