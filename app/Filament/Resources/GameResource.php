<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GameResource\Pages;
use App\Filament\Resources\GameResource\RelationManagers;
use App\Models\Game;
use App\Rules\GameOrigin;
use App\Rules\GameSlug;
use App\Services\Glosis\GlosisSettings;
use App\Services\Glosis\GooglePurchaseVerifier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 1;

    /**
     * Krypterade värden i settings. Visas aldrig i formuläret; ett tomt fält
     * behåller det sparade värdet.
     */
    public const SECRET_SETTINGS = [
        'app_store_connect.private_key',
        'revenuecat.webhook_secret',
        'anthropic.api_key',
        'elevenlabs.api_key',
        'google_play.service_account_json',
    ];

    /**
     * Anti-cheat presets — named configurations that Mattias can pick from a dropdown.
     * Each preset configures bounds and proof requirements.
     */
    public const ANTI_CHEAT_PRESETS = [
        'none' => [
            'label' => 'None — default bounds only',
            // Samma beteende som ett spel utan inställningar (AntiCheatService:s standardvärden).
            'description' => 'No preset: default bounds apply (score 0–86400). A proof, if sent, is still checked as a time in milliseconds. Use for casual games without competitive leaderboards.',
            'config' => [],
        ],
        'time_ms' => [
            'label' => 'Time-based (milliseconds)',
            'description' => 'Score = solve time in ms. Lower is better. Validates minimum/maximum time and optionally requires proof-of-play with FNV-1a signature.',
            'config' => [
                'score_unit' => 'ms',
                'min_score' => 5000,
                'max_score' => 86400000,
                'proof_algorithm' => 'fnv1a',
                'proof_time_tolerance' => 1000,
                'min_moves' => 1,
            ],
        ],
        'time_seconds' => [
            'label' => 'Time-based (seconds)',
            'description' => 'Score = solve time in seconds. Lower is better.',
            'config' => [
                'score_unit' => 'seconds',
                'min_score' => 5,
                'max_score' => 86400,
                'proof_algorithm' => 'fnv1a',
                'proof_time_tolerance' => 2,
                'min_moves' => 1,
            ],
        ],
        'points' => [
            'label' => 'Points-based (higher is better)',
            'description' => 'Score = points. Higher is better. Validates against a maximum cap.',
            'config' => [
                'min_score' => 0,
                'max_score' => 1000000,
                // Poäng är ingen tid: bevisets t jämförs inte med poängen.
                'proof_time_tolerance' => 0,
            ],
        ],
        'points_with_proof' => [
            'label' => 'Points-based with proof',
            'description' => 'Score = points. Higher is better. Requires proof-of-play signature.',
            'config' => [
                'min_score' => 0,
                'max_score' => 1000000,
                // Poäng är ingen tid: bevisets t jämförs inte med poängen.
                'proof_time_tolerance' => 0,
                'require_proof' => true,
                'proof_algorithm' => 'fnv1a',
                'min_moves' => 1,
            ],
        ],
    ];

    // Varje nyckel som något förval sätter, med förvalets värde eller null. Byte av förval
    // får inte lämna dolda nycklar (score_unit, proof_time_tolerance) från det förra kvar.
    public static function presetSettings(string $preset): array
    {
        $keys = collect(self::ANTI_CHEAT_PRESETS)->flatMap(fn (array $p) => array_keys($p['config']))->unique();
        $config = self::ANTI_CHEAT_PRESETS[$preset]['config'];

        return $keys->mapWithKeys(fn (string $key) => [$key => $config[$key] ?? null])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        // Provisioneringens slugregel gäller bara nya spel; redigering
                        // av ett befintligt spel (tocco, tells, glosis) blockeras aldrig.
                        ->rule(new GameSlug, fn (?Game $record) => $record === null)
                        ->helperText('Nya spel: 3-40 tecken, gemener, siffror och enkla bindestreck (t.ex. "kattsvep"). Blir också reponamn och underdomän.'),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\Textarea::make('description')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\Toggle::make('is_listed')
                        ->label('Listad i publika spellistan')
                        ->helperText('Demo-spel från create_game skapas olistade. Slå på vid Live.')
                        ->default(true),
                    Forms\Components\TagsInput::make('allowed_origins')
                        ->label('Tillåtna origins (CORS)')
                        ->placeholder('https://ordjakt.apps.humblebrag.se')
                        // Samma tak som MCP-verktyget set_allowed_origins.
                        ->rules(['nullable', 'array', 'max:20'])
                        ->nestedRecursiveRules([new GameOrigin])
                        ->helperText('Webbadresser som spelets frontend körs på. https, utan path.'),
                ]),

            Forms\Components\Section::make('Anti-Cheat')
                ->description('How leaderboard scores are validated. The Dev Kit manual explains the chosen type to the AI assistant.')
                ->schema([
                    Forms\Components\Select::make('settings.anti_cheat_preset')
                        ->label('Preset')
                        ->options(collect(self::ANTI_CHEAT_PRESETS)->mapWithKeys(fn ($p, $k) => [$k => $p['label']]))
                        ->default('none')
                        // Select validerar inte mot sina options. Tomt är tillåtet: äldre
                        // spel saknar preset och helperText faller då tillbaka på none.
                        ->rules(['nullable', Rule::in(array_keys(self::ANTI_CHEAT_PRESETS))])
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! isset(self::ANTI_CHEAT_PRESETS[$state])) {
                                return;
                            }
                            foreach (self::presetSettings($state) as $key => $value) {
                                $set("settings.anti_cheat.{$key}", $value);
                            }
                        })
                        ->helperText(fn (Get $get) => self::ANTI_CHEAT_PRESETS[$get('settings.anti_cheat_preset') ?? 'none']['description'] ?? ''),
                    // Förvalets nycklar utan eget fält. Utan dem sparar formuläret aldrig
                    // nycklarna och raderar dem vid omsparning (settings.anti_cheat ersätts helt).
                    Forms\Components\Hidden::make('settings.anti_cheat.score_unit'),
                    Forms\Components\Hidden::make('settings.anti_cheat.proof_algorithm'),
                    Forms\Components\Hidden::make('settings.anti_cheat.proof_time_tolerance'),
                    Forms\Components\TextInput::make('settings.anti_cheat.min_score')
                        ->label('Minimum score')
                        ->numeric()
                        ->default(0)
                        ->helperText('Scores below this are rejected'),
                    Forms\Components\TextInput::make('settings.anti_cheat.max_score')
                        ->label('Maximum score')
                        ->numeric()
                        ->default(86400)
                        ->helperText('Scores above this are rejected'),
                    Forms\Components\Toggle::make('settings.anti_cheat.require_proof')
                        ->label('Require proof-of-play')
                        ->default(false)
                        ->helperText('If enabled, submissions without a proof object are rejected'),
                    Forms\Components\TextInput::make('settings.anti_cheat.min_moves')
                        ->label('Minimum moves/actions')
                        ->numeric()
                        ->default(1)
                        ->helperText('Minimum action count required in proof'),
                ]),

            Forms\Components\Section::make('Theme Pack Contents')
                ->description('Define what themes each theme pack contains. Products themselves are managed in the Products tab after saving the game.')
                ->collapsed()
                ->schema([
                    Forms\Components\KeyValue::make('settings.theme_packs')
                        ->label('Theme pack contents')
                        ->keyLabel('Theme pack ID')
                        ->valueLabel('Theme IDs (comma-separated)')
                        ->helperText('Map theme pack IDs to the theme IDs they contain. Values are comma-separated.')
                        ->default([]),
                ]),

            Forms\Components\Section::make('App Store Connect API')
                ->description('Credentials for syncing products to App Store Connect via the iap:sync command. Create a key at Users and Access → Integrations → App Store Connect API.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('settings.app_store_connect.issuer_id')
                        ->label('Issuer ID')
                        ->helperText('UUID from the API key page'),
                    Forms\Components\TextInput::make('settings.app_store_connect.key_id')
                        ->label('Key ID')
                        ->helperText('10-character identifier'),
                    Forms\Components\Textarea::make('settings.app_store_connect.private_key')
                        ->label('Private key (.p8 contents)')
                        ->rows(5)
                        ->helperText('Paste the full contents of the .p8 file (including BEGIN/END lines). Stored encrypted. Leave blank to keep existing.')
                        // Never show the stored encrypted blob back to the user
                        ->formatStateUsing(fn () => null)
                        ->dehydrateStateUsing(function ($state, ?Game $record) {
                            if (filled($state)) {
                                return Crypt::encryptString($state);
                            }

                            return $record?->settings['app_store_connect']['private_key'] ?? null;
                        }),
                    Forms\Components\TextInput::make('settings.app_store_connect.app_apple_id')
                        ->label('App Apple ID')
                        ->helperText('Numeric ID from App Store Connect → App Information (not the bundle ID)'),
                ]),

            Forms\Components\Section::make('RevenueCat Integration')
                ->description('Configure RevenueCat for in-app purchases. Public key is used by the client; webhook secret is used by the server to verify incoming events.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('settings.revenuecat.ios_public_key')
                        ->label('iOS public API key')
                        ->helperText('Starts with "appl_" — safe to ship in client'),
                    Forms\Components\TextInput::make('settings.revenuecat.android_public_key')
                        ->label('Android public API key')
                        ->helperText('Starts with "goog_"'),
                    Forms\Components\TextInput::make('settings.revenuecat.webhook_secret')
                        ->label('Webhook signing secret')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank to keep existing secret. To set/change: paste the same value you put in RevenueCat → Webhooks → Authorization header.')
                        // Always show empty on load — never echo back the stored secret
                        ->formatStateUsing(fn () => null)
                        // If input is empty, preserve the existing stored value;
                        // otherwise encrypt the new plaintext secret
                        ->dehydrateStateUsing(function ($state, ?Game $record) {
                            if (filled($state)) {
                                return Crypt::encryptString($state);
                            }

                            return $record?->settings['revenuecat']['webhook_secret'] ?? null;
                        }),
                    Forms\Components\Placeholder::make('webhook_url')
                        ->label('Webhook URL (for RevenueCat dashboard)')
                        ->content(fn ($record) => $record
                            ? rtrim(config('app.url'), '/')."/api/v1/webhooks/revenuecat/{$record->slug}"
                            : 'Save the game first to generate the URL'),
                ]),

            Forms\Components\Section::make('Anthropic (Claude)')
                ->description('API key used server-side by features that call Claude, e.g. Glosis photo scan (POST /api/v1/games/{slug}/scan). Never sent to the app.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('settings.anthropic.api_key')
                        ->label('Anthropic API key')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank to keep the existing key. Stored encrypted; the stored value is never shown here.')
                        // Always show empty on load — never echo back the stored key
                        ->formatStateUsing(fn () => null)
                        ->dehydrateStateUsing(function ($state, ?Game $record) {
                            if (filled($state)) {
                                return Crypt::encryptString(trim($state));
                            }

                            return $record?->settings['anthropic']['api_key'] ?? null;
                        }),
                    Forms\Components\Placeholder::make('anthropic_api_key_status')
                        ->label('Status')
                        ->content(fn (?Game $record) => filled($record?->settings['anthropic']['api_key'] ?? null)
                            ? 'A key is stored.'
                            : 'No key stored — photo scan answers 503 scan_unavailable.'),
                ]),

            Forms\Components\Section::make('Google Play: köpkontroll')
                ->description('Tjänstekontot som frågar Google Play Developer API om Android-köp av Guldstjärnan (fotoskanning, studioröst). Utan det svarar Android 501 platform_not_supported.')
                ->collapsed()
                ->visible(fn (?Game $record) => $record?->slug === 'glosis')
                ->schema([
                    Forms\Components\Textarea::make('settings.google_play.service_account_json')
                        ->label('Tjänstekontots JSON-nyckel')
                        ->rows(5)
                        ->helperText('Klistra in hela JSON-filen från Google Cloud (IAM → Service accounts → Keys). Kontot behöver "'.GooglePurchaseVerifier::REQUIRED_PERMISSION.'" för appen i Play Console. Sparas krypterad och visas aldrig. Tomt fält behåller den sparade.')
                        // Visa aldrig det sparade värdet
                        ->formatStateUsing(fn () => null)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (filled($value) && (! is_string($value) || GlosisSettings::parseServiceAccountJson(trim($value)) === null)) {
                                $fail('Det här är inte JSON-nyckeln för ett service account (type, client_email och private_key krävs).');
                            }
                        })
                        ->dehydrateStateUsing(function ($state, ?Game $record) {
                            if (filled($state)) {
                                return Crypt::encryptString(trim($state));
                            }

                            return $record?->settings['google_play']['service_account_json'] ?? null;
                        }),
                    Forms\Components\TextInput::make('settings.google_play.package_name')
                        ->label('Paketnamn')
                        ->placeholder(GlosisSettings::DEFAULT_GOOGLE_PLAY_PACKAGE)
                        ->regex('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim($state) : null)
                        ->helperText('Tomt = '.GlosisSettings::DEFAULT_GOOGLE_PLAY_PACKAGE.'.'),
                    Forms\Components\Placeholder::make('google_play_status')
                        ->label('Status')
                        ->content(function (?Game $record) {
                            if (! filled($record?->settings['google_play']['service_account_json'] ?? null)) {
                                return 'Inget tjänstekonto: Android-köp svarar 501 platform_not_supported.';
                            }
                            $account = GlosisSettings::for($record)->googlePlayServiceAccount();

                            return $account === null
                                ? 'Sparat värde går inte att läsa. Klistra in nyckeln igen.'
                                : 'Tjänstekonto sparat: '.$account['client_email'].'.';
                        }),
                ]),

            Forms\Components\Section::make('Glosis: gratisnivå')
                ->description('Studiorösten utan Guldstjärnan, skyddad med Apple App Attest (iOS). Att fota läxan kräver alltid Guld. Stäng av här för att stoppa allt gratis direkt; Guld påverkas inte.')
                ->collapsed()
                ->visible(fn (?Game $record) => $record?->slug === 'glosis')
                ->schema([
                    Forms\Components\Toggle::make('settings.free.enabled')
                        ->label('Gratisnivån på')
                        ->default(false)
                        ->helperText('Av = appen får 503 free_unavailable och använder telefonens röst.'),
                    Forms\Components\TextInput::make('settings.app_attest.team_id')
                        ->label('Apple Team ID')
                        ->regex('/^[A-Z0-9]{10}\z/')
                        ->helperText('10 tecken, Apple Developer → Membership. Krävs för App Attest (RP ID = Team ID + bundle-id).'),
                    Forms\Components\TextInput::make('settings.free.tts_daily_new_limit')->label('Nya gratisord per dygn (alla)')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_TTS_DAILY_NEW_LIMIT)->helperText('Egen räknare, äter inte av Guld-taket. Per nyckel gäller alltid '.GlosisSettings::TTS_DAILY_NEW_PER_KEY.'.'),
                    Forms\Components\TextInput::make('settings.free.tts_ip_daily_new_limit')->label('Nya gratisord per IP och dygn')->integer()->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_TTS_IP_DAILY_NEW_LIMIT),
                    Forms\Components\TextInput::make('settings.free.monthly_budget_usd')->label('Gratisnivåns månadsbudget (USD)')->numeric()->minValue(0)->step(0.01)
                        ->placeholder((string) GlosisSettings::DEFAULT_FREE_MONTHLY_BUDGET_USD)->helperText('Ryms i den gemensamma månadsbudgeten.'),
                ]),

            Forms\Components\Section::make('Glosis: AI-kostnader')
                ->description('Gränser för fotoskanningen och studiorösten, och en gemensam månadsbudget för alla betalda AI-anrop. Tomt fält = standardvärdet. Användningen syns under AI-användning.')
                ->collapsed()
                ->visible(fn (?Game $record) => $record?->slug === 'glosis')
                ->schema([
                    Forms\Components\TextInput::make('settings.scan.weekly_limit')
                        ->label('Skanningar per köp och vecka')
                        ->integer()
                        ->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_SCAN_WEEKLY_LIMIT)
                        ->helperText('Riktiga köp. ISO-vecka i Stockholm. Tomt = '.GlosisSettings::DEFAULT_SCAN_WEEKLY_LIMIT.'.'),
                    Forms\Components\TextInput::make('settings.scan.sandbox_weekly_limit')
                        ->label('Skanningar per sandbox-köp och vecka')
                        ->integer()
                        ->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT)
                        ->helperText('TestFlight-köp och Google Plays testköp (licenstestare). Tomt = '.GlosisSettings::DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT.'.'),
                    Forms\Components\TextInput::make('settings.ai.monthly_budget_usd')
                        ->label('Månadsbudget för AI (USD)')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->placeholder((string) GlosisSettings::DEFAULT_MONTHLY_BUDGET_USD)
                        ->helperText('Skanning och studioröst tillsammans, kalendermånad i Stockholm. När den är slut svarar API:t 503 budget_exhausted. Tomt = '.GlosisSettings::DEFAULT_MONTHLY_BUDGET_USD.'.'),
                    Forms\Components\TextInput::make('settings.tts.daily_new_limit')
                        ->label('Nya studioröst-ord per dygn (alla köp)')
                        ->integer()
                        ->minValue(0)
                        ->placeholder((string) GlosisSettings::DEFAULT_TTS_DAILY_NEW_LIMIT)
                        ->helperText('Ord som redan finns sparade räknas inte. Per köp gäller högst '.GlosisSettings::TTS_DAILY_NEW_PER_PURCHASE.' nya ord per dygn. Tomt = '.GlosisSettings::DEFAULT_TTS_DAILY_NEW_LIMIT.'.'),
                    Forms\Components\TextInput::make('settings.elevenlabs.api_key')
                        ->label('ElevenLabs API key')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank to keep the existing key. Stored encrypted; the stored value is never shown here.')
                        // Always show empty on load — never echo back the stored key
                        ->formatStateUsing(fn () => null)
                        ->dehydrateStateUsing(function ($state, ?Game $record) {
                            if (filled($state)) {
                                return Crypt::encryptString(trim($state));
                            }

                            return $record?->settings['elevenlabs']['api_key'] ?? null;
                        }),
                    Forms\Components\TextInput::make('settings.elevenlabs.voice_id')
                        ->label('ElevenLabs voice ID (reserv för engelska)')
                        ->regex('/^[A-Za-z0-9]{1,64}$/')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim($state) : null)
                        ->helperText('Räknas som engelsk kvinnlig röst när ingen sådan är vald på sidan Röster. Rösterna per språk väljs där.'),
                    Forms\Components\Placeholder::make('elevenlabs_status')
                        ->label('Studioröst')
                        ->content(function (?Game $record) {
                            if (! filled($record?->settings['elevenlabs']['api_key'] ?? null)) {
                                return 'Ingen nyckel: nya ord svarar 503 tts_unavailable, appen använder telefonens röst.';
                            }
                            $voices = array_filter(GlosisSettings::for($record)->elevenLabsVoices(), fn (array $slots) => array_filter($slots) !== []);

                            return $voices === []
                                ? 'Nyckel sparad men ingen röst vald: välj röster på sidan Röster.'
                                : 'Nyckel sparad. Röster för: '.implode(', ', array_map(fn ($code) => GlosisSettings::LANGUAGES[$code], array_keys($voices))).'.';
                        }),
                ]),

            Forms\Components\Section::make('Advanced Settings')
                ->description('Raw settings JSON for anything not covered above.')
                ->collapsed()
                ->schema([
                    Forms\Components\KeyValue::make('settings.extra')
                        ->label('Custom settings')
                        ->default([]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\IconColumn::make('is_listed')->boolean()->label('Listad'),
                Tables\Columns\TextColumn::make('leaderboard_types_count')
                    ->counts('leaderboardTypes')
                    ->label('Leaderboards'),
                Tables\Columns\TextColumn::make('achievement_definitions_count')
                    ->counts('achievementDefinitions')
                    ->label('Achievements'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('devkit')
                    ->label('Dev Kit')
                    ->icon('heroicon-o-command-line')
                    ->url(fn (Game $record) => static::getUrl('devkit', ['record' => $record])),
                Tables\Actions\Action::make('releases')
                    ->label('Releases')
                    ->icon('heroicon-o-rocket-launch')
                    ->visible(fn () => auth()->user()?->isPlatformAdmin() === true)
                    ->url(fn (Game $record) => static::getUrl('releases', ['record' => $record])),
                Tables\Actions\Action::make('voices')
                    ->label('Röster')
                    ->icon('heroicon-o-speaker-wave')
                    ->visible(fn (Game $record) => $record->slug === 'glosis')
                    ->url(fn (Game $record) => static::getUrl('voices', ['record' => $record])),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LeaderboardTypesRelationManager::class,
            RelationManagers\AchievementDefinitionsRelationManager::class,
            RelationManagers\ProductsRelationManager::class,
            RelationManagers\DailyContentPoolsRelationManager::class,
            RelationManagers\RemoteConfigsRelationManager::class,
            RelationManagers\GameEventsRelationManager::class,
            RelationManagers\MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGames::route('/'),
            'create' => Pages\CreateGame::route('/create'),
            'edit' => Pages\EditGame::route('/{record}/edit'),
            'devkit' => Pages\GameDevKit::route('/{record}/devkit'),
            'releases' => Pages\GameReleases::route('/{record}/releases'),
            'voices' => Pages\GameVoices::route('/{record}/voices'),
        ];
    }
}
