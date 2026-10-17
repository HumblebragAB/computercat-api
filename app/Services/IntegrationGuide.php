<?php

namespace App\Services;

use App\Filament\Resources\GameResource;
use App\Matches\RulesetRegistry;
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
        'matches' => 'Turn-based Matches',
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
        if (RulesetRegistry::forGame($game) !== []) {
            $features[] = 'matches';
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
        $lines[] = '- **Tokens expire 90 days after they are issued.** Refresh at launch when the stored token is older than 24 hours (see Authentication). On 401, create a new anonymous user and re-sync.';
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
            $lines[] = 'Store token persistently together with the time it was issued, and keep the same user for as long as the token works.';
            $lines[] = '';
            $lines[] = '### Refresh on launch';
            $lines[] = 'Tokens expire 90 days after they are issued. At app start, if the stored token is older than 24 hours, exchange it for a new one:';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/auth/refresh";
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response: `{"token": "2|def...", "user": {"id": 1, "display_name": "Player", "is_anonymous": true}, "expires_at": "2027-01-11T12:00:00+00:00"}`';
            $lines[] = '';
            $lines[] = 'Replace the stored token and its issue time with the new ones. If the response is lost, keep using the old token: the old token stays valid until the new one has been used. From the first request with the new token, the old one keeps working for up to 10 more minutes, so requests already in flight (or other tabs) do not fail. A player who opens the game at least once every 90 days keeps the same account.';
            $lines[] = '';
            $lines[] = 'If the refresh (or any other request) returns 401, the token has expired, the user was removed or the account is banned: create a new anonymous user and re-sync. A banned account gets 401 on every authenticated route, including refresh; only `POST /auth/login` returns 403 for a banned account. 429 on refresh means rate limited: keep the current token and try again on next launch.';
            $lines[] = '';
            $lines[] = "If the token is younger than 24 hours, `GET {$baseUrl}/auth/me` is enough to verify it.";
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
            $lines[] = "POST {$baseUrl}/games/".self::code($game->slug).'/leaderboards/'.self::code($ex->slug);
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
                    $lines[] = '| `t` | Elapsed time in milliseconds (integer) |';
                    $lines[] = '| `sig` | Signature over `pid\\|mc\\|gh\\|t` |';
                    $lines[] = '';
                    // Samma regel som AntiCheatService::validateProof: t jämförs med poängen bara när toleransen > 0.
                    $tolerance = $acConfig['proof_time_tolerance'] ?? 1000;
                    $tolerance = is_int($tolerance) ? $tolerance : 1000;
                    $unit = ($acConfig['score_unit'] ?? null) === 'seconds' ? 'seconds' : 'ms';
                    $lines[] = $tolerance > 0
                        ? "- `t` must match the score within `{$tolerance}` {$unit}".($unit === 'seconds' ? ' (the server divides `t` by 1000)' : '').'.'
                        : '- `t` is not compared with the score (the score is not a time).';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/leaderboards/'.self::code($ex->slug);
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Get my rank';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/leaderboards/'.self::code($ex->slug).'/me';
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = 'Submit in background after completing a round. Queue if offline.';
            $lines[] = '';
        }

        // Begärs achievements uttryckligen visas avsnittet också utan definitioner, så att ett nytt
        // spel vet hur det fungerar. Standardvalet (defaultFeatures) tar bara med det när definitioner finns.
        if (in_array('achievements', $features)) {
            $lines[] = '## Achievements';
            $lines[] = '';
            $lines[] = 'Evaluated **client-side**. Server stores unlock state for cross-device sync.';
            $lines[] = '';
            $lines[] = '### Definitions';
            if ($game->achievementDefinitions->count()) {
                $lines[] = '| Slug | Name | Description | Icon |';
                $lines[] = '|------|------|-------------|------|';
                foreach ($game->achievementDefinitions->sortBy('sort_order') as $a) {
                    $s = $a->is_secret ? ' (secret)' : '';
                    $lines[] = '| `'.self::code($a->slug).'` | '.self::inline($a->name).$s.' | '.self::inline($a->description).' | '.self::inline($a->icon).' |';
                }
            } else {
                $lines[] = 'None yet. Create them with the Computercat connector tool `upsert_achievement` (slug, name, description, icon).';
            }
            $lines[] = '';
            $lines[] = '### Report unlocks';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/games/".self::code($game->slug).'/achievements';
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = 'Content-Type: application/json';
            $lines[] = '';
            $lines[] = '{"slugs": ["first-win", "speed-demon"]}';
            $lines[] = '```';
            $lines[] = 'Response `201`:';
            $lines[] = '```';
            $lines[] = '{"unlocked": [{"slug": "first-win", "name": "...", "description": "...", "icon": "...", "unlocked_at": "..."}], "already_unlocked": 1, "unknown": ["typo-slug"], "rejected": ["solve_100"]}';
            $lines[] = '```';
            $lines[] = '- Idempotent: an already-unlocked slug is counted in `already_unlocked` (an integer) and not unlocked again.';
            $lines[] = '- `unknown`: slugs with no active definition. Show them (a typo in the game never fixes itself).';
            $lines[] = '- `rejected`: refused by a plausibility threshold (minimum number of `progress.results` in the cloud save `main`). Thresholds are set by Computercat on request, not by the game. Save before reporting, and report the full local list at launch so a rejected unlock is retried.';
            $lines[] = '';
            $lines[] = '### Get my achievements';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/achievements/me';
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
            $lines[] = "PUT {$baseUrl}/games/".self::code($game->slug).'/saves/main';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/saves/main';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/ownership';
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
            $lines[] = "const ownership = await fetch('{$baseUrl}/games/".self::code($game->slug)."/ownership');";
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/daily/{poolKey}';
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": <item>, "date": "2026-04-02", "pool_key": "puzzles"}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Get content for a past date';
            $lines[] = '```';
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/daily/{poolKey}/{date}';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/pools/{poolKey}/items?count=20';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/streaks/{key}';
            $lines[] = 'Authorization: Bearer <token>';
            $lines[] = '```';
            $lines[] = 'Response:';
            $lines[] = '```json';
            $lines[] = '{"data": {"streak_key": "daily", "current_streak": 7, "longest_streak": 14, "last_activity_date": "2026-04-02", "freeze_balance": 2}}';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '### Record activity';
            $lines[] = '```';
            $lines[] = "POST {$baseUrl}/games/".self::code($game->slug).'/streaks/{key}/record';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/config';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/stats/me';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/events';
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
            $lines[] = "GET {$baseUrl}/games/".self::code($game->slug).'/events/{slug}';
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

        // ── Turn-based Matches ───────────────────────────────────────────────
        if (in_array('matches', $features)) {
            $slug = self::code($game->slug);
            $rulesets = RulesetRegistry::forGame($game);
            $lines[] = '## Turn-based Matches';
            $lines[] = '';
            $lines[] = 'Two-player, turn-based matches played over the API. The server owns the rules and any hidden state (for example a secret number), so the opponent never receives it.';
            $lines[] = '';
            $lines[] = 'Rulesets available to this game: '.($rulesets === [] ? 'none' : implode(', ', array_map(fn ($r) => '`'.self::code($r).'`', $rulesets))).'. Any other ruleset gets 403 `ruleset_not_allowed`.';
            $lines[] = '';
            $lines[] = '**Games built from the build guide use its `matches` client module when their build guide version has one (planned for v3). Until then, do not call these routes directly; ask Computercat (the platform team) instead.** The module will poll and handle ETag, `version`, backoff and errors. The routes below are for reference.';
            $lines[] = '';
            $lines[] = 'All routes need `Authorization: Bearer <token>`.';
            $lines[] = '';
            $lines[] = '| Route | Body | Does |';
            $lines[] = '|-------|------|------|';
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches` | `{ruleset, options?, name?}` | Creates a match, you take seat 0. Returns 201 `{match, join_token}` |";
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches/join` | `{token, name?}` | Joins as seat 1 and starts the match |";
            $lines[] = "| `GET {$baseUrl}/games/{$slug}/matches/active` | none | Your open matches in this game: `{data: [{id, ruleset, status, your_seat}]}`. Use it to reconnect after a reload |";
            $lines[] = "| `GET {$baseUrl}/games/{$slug}/matches/{id}?since={seq}` | none | Snapshot plus events after `seq` |";
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches/{id}/actions` | `{type, payload, version}` | One move |";
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches/{id}/leave` | none | Leave. The match becomes `abandoned`. On a match that is already `finished` or `abandoned` it is a no-op that returns 200 with the unchanged snapshot |";
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches/{id}/rematch` | none | Request a rematch (only when `finished`). Asking again from the same seat is an idempotent no-op |";
            $lines[] = "| `POST {$baseUrl}/games/{$slug}/matches/{id}/invite` | none | New invite for your own `waiting` match (creator, seat 0, only). Returns 200 `{match, join_token}`; the old token stops working and the 15 min wait starts over. Otherwise 422 `wrong_phase` |";
            $lines[] = '';
            $lines[] = '`name` is 1-20 characters without control characters (default `Spelare 1` / `Spelare 2` by seat); an invalid name gives 422.';
            $lines[] = '';
            $lines[] = '### Response shape (GET and after every POST except create and invite, which wrap it as `{match, join_token}`)';
            $lines[] = '```json';
            $lines[] = '{"id": "01J...", "ruleset": "reveal", "status": "active", "version": 7, "your_seat": 0, "players": [{"seat": 0, "name": "Anna", "connected": true}, {"seat": 1, "name": "Bo", "connected": false}], "state": {}, "you": {}, "events": [{"seq": 12, "type": "left", "seat": 1, "payload": {}, "at": "2026-10-17T10:00:00+00:00"}]}';
            $lines[] = '```';
            $lines[] = '- `status`: `waiting`, `active`, `finished` or `abandoned`';
            $lines[] = '- `state` is the public state. `you` is your own view of your hidden state. The opponent\'s hidden state is never sent.';
            $lines[] = '- `events` are ordered by `seq`. With `since` you get the events after it (at most 200); without it you get the 200 latest. Remember the highest `seq` you have seen.';
            $lines[] = '- Base events: `left` (a player left), `expired` (the match timed out), `rematch_requested` and `rematch` with `{match_id}` in the payload. The seat the event concerns is in the event\'s `seat` field. Rulesets add their own events.';
            $lines[] = '';
            $lines[] = '### Polling';
            $lines[] = '- Poll `GET /matches/{id}` every 1.5 s and pause while the tab is hidden (`visibilitychange`). The client module (when available) also backs off to 5 s on network errors.';
            $lines[] = '- The response has an `ETag` (`{version}-{c0}{c1}`: the version plus whether seat 0 and 1 are connected). Send it back as `If-None-Match`; unchanged gives `304` with no body. The responses from join, actions, leave, rematch and invite carry the same `ETag` as a poll would.';
            $lines[] = "- With `fetch`, send `If-None-Match` yourself and use `cache: 'no-store'`, so the browser cache neither answers for the server nor turns a 304 into a cached 200. `ETag` and `Retry-After` are exposed via CORS, so the game can read them.";
            $lines[] = '- Connection status is part of the ETag, so you also learn when the opponent drops without any move being made.';
            $lines[] = '- Polling is what marks you as connected.';
            $lines[] = '';
            $lines[] = '### Moves and `version`';
            $lines[] = '- Send the `version` of the snapshot you are acting on. If it is not the current one the server answers `409 {"code": "stale", "version": N}` with no side effect. Poll again and retry if the move is still valid. This makes double taps and resent requests harmless.';
            $lines[] = '- A rejected move changes nothing (not `version`, `state` or the event log).';
            $lines[] = '- Numbers in a payload must be JSON integers. `"37"`, `37.0` and `37.5` give 422 `invalid_payload`.';
            $lines[] = '';
            $lines[] = '### Inviting a friend';
            $lines[] = '`POST /matches` returns `join_token`. Build the link yourself as `https://<game origin>/?join=<token>` and show it as a QR code. The friend\'s client reads `join` from the URL and calls `POST /matches/join`. The server does not know your origin. The token is single use.';
            $lines[] = '';
            $lines[] = 'The token is only returned once and the server keeps only a hash of it. If the creator reloads while waiting (find the match with `GET /matches/active`), call `POST /matches/{id}/invite` to get a new `join_token` and show a fresh QR code. The old link stops working.';
            $lines[] = '';
            $lines[] = '### Rematch';
            $lines[] = 'Only allowed when `status` is `finished`. The first call adds `rematch_requested` (with `seat`). When both seats have asked, a new match is created with the same players, seats, names and options, and the old match gets a `rematch` event with `{match_id}`. Switch to that match when you see the event. A rematch needs no `join_token`.';
            $lines[] = '';
            $lines[] = '### Errors';
            $lines[] = 'Coded errors are `{"message": "...", "code": "...", ...extra}` (for example `version` on `stale`). 404 is `{message}` only. Validation errors are `{"message": "...", "errors": {...}}`. 401 means the token is missing or expired: refresh it as described under Authentication.';
            $lines[] = '';
            $lines[] = '| Status | `code` | Meaning |';
            $lines[] = '|--------|--------|---------|';
            $lines[] = '| 401 | none | Token missing or expired. Refresh it (see Authentication) |';
            $lines[] = '| 403 | `ruleset_not_allowed` | The game has no such ruleset |';
            $lines[] = '| 404 | none | No such match, or you are not in it. The two cases look the same |';
            $lines[] = '| 409 | `stale` | Old `version`; the body has the current `version`. Poll and retry |';
            $lines[] = '| 409 | `own_match` | You tried to join your own match. The token is not used up |';
            $lines[] = '| 410 | `invite_expired` | Unknown, used or expired token (all look the same) |';
            $lines[] = '| 422 | `wrong_phase` | Not allowed in the match\'s current status (for example rematch before `finished`, invite after the match started or by seat 1) |';
            $lines[] = '| 422 | `not_your_turn`, `wrong_phase`, `already_locked`, `already_ready`, `out_of_range`, `invalid_payload`, `unknown_action` | The move is illegal. Ruleset-specific |';
            $lines[] = '| 422 | validation | Bad request fields; see `errors` |';
            $lines[] = '| 429 | `too_many_active` | You already have 5 matches in `waiting`/`active` in this game. Leave one first. Do not retry on a timer |';
            $lines[] = '| 429 | `rate_limited` | Too many requests. Back off and retry after `Retry-After` |';
            $lines[] = '';
            $lines[] = 'Limits per user: create 10/min, join 10/min (30/min per IP), poll 120/min, actions/leave/rematch/invite 60/min.';
            $lines[] = '';
            $lines[] = '### Lifecycle';
            $lines[] = '- `waiting` with no opponent for 15 min becomes `abandoned`.';
            $lines[] = '- `active` with no move for 30 min becomes `abandoned`. Every move resets the timer.';
            $lines[] = '- A player counts as `connected` when they polled within the last 10 s. There is no automatic loss on disconnect in v1.';
            $lines[] = '- Hidden state is cleared when the match finishes or is abandoned, so nothing is revealed that the rules do not reveal. Finished and abandoned matches are deleted after 7 days; a poll then gives 404.';
            $lines[] = '- The new match from a rematch is not checked against the cap of 5.';
            $lines[] = '';
            $lines[] = '### Required in the game';
            $lines[] = '- Show a visible state when the connection is lost: the opponent\'s `connected` is false ("waiting for Anna, connection lost"), and when polling fails or `onError` fires. Never stand silently still.';
            $lines[] = '- Handle `abandoned` and 404 on poll by returning the player to the menu with a message.';
            $lines[] = '';
        }

        $lines[] = '## Error Handling';
        $lines[] = '';
        $lines[] = 'All errors return JSON: `{"message": "...", "errors": {...}}`';
        $lines[] = '';
        $lines[] = '| Status | Meaning | Action |';
        $lines[] = '|--------|---------|--------|';
        $lines[] = '| 401 | Token expired or invalid | Create new anonymous user |';
        $lines[] = '| 404 | Not found | — |';
        $lines[] = '| 409 | Save conflict | Pull, merge, retry |';
        $lines[] = '| 422 | Validation | Check `errors` |';
        $lines[] = '| 429 | Rate limited | Back off, retry |';
        $lines[] = '';
        $lines[] = '## Rate Limits';
        $lines[] = '- General: 60/min';
        $lines[] = '- Auth (anonymous, login): 10/min per IP';
        $lines[] = '- Token refresh: 30/min per user';
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
