<?php

// Provisionering av Demo-spel (spec 2026-10-13, delprojekt 2c). Klienterna
// läser bara härifrån. Värdena hamnar aldrig i verktygssvar eller loggar.
return [
    'github' => [
        'api_url' => 'https://api.github.com',
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        // Relativ till storage/, t.ex. app/private/github-app.pem. Filen ska ha läge 600.
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
        // Organisationen där spelrepona skapas (POST /orgs/{owner}/repos). Inget mallrepo.
        'owner' => env('GITHUB_OWNER', 'HumblebragAB'),
    ],

    'coolify' => [
        // Utan /api/v1; klienten lägger till det.
        'url' => env('COOLIFY_URL'),
        'token' => env('COOLIFY_TOKEN'),
        'project_uuid' => env('COOLIFY_PROJECT_UUID'),
        'environment_name' => env('COOLIFY_ENVIRONMENT_NAME', 'production'),
        'server_uuid' => env('COOLIFY_SERVER_UUID'),
        'github_app_uuid' => env('COOLIFY_GITHUB_APP_UUID'),
    ],

    'siteops' => [
        // Utan /api/v1; klienten lägger till det.
        'url' => env('SITEOPS_URL'),
        'api_key' => env('SITEOPS_API_KEY'),
    ],

    'demo' => [
        'domain_suffix' => 'apps.humblebrag.se',
        // Konvention (demo/demo), inte en hemlighet. Visas för spelets medlemmar.
        'basic_auth_user' => env('DEMO_BASIC_AUTH_USER'),
        'basic_auth_password' => env('DEMO_BASIC_AUTH_PASSWORD'),
    ],

    'slack_webhook_url' => env('SLACK_ALERT_WEBHOOK_URL'),

    // Slugs som aldrig får bli spel: fasta namn plus befintliga underdomäner
    // under apps.humblebrag.se (uppdateras när Coolify-appar läggs till för hand).
    'reserved_slugs' => array_values(array_unique(array_filter(array_merge(
        ['www', 'api', 'admin', 'app', 'apps', 'demo', 'mail', 'spel'],
        // Gemener: DNS skiljer inte på skiftläge, och slugregeln kräver gemener.
        array_map(fn ($slug) => strtolower(trim($slug)), explode(',', (string) env('PROVISIONING_RESERVED_SUBDOMAINS', ''))),
    ), fn ($slug) => $slug !== ''))),

    'max_demo_games' => 15,
    'max_new_per_day' => 5,
    'repo_ready_timeout_minutes' => 3,
    // Invarianten: ett spel utan bygge larmar först när runnen är så här gammal.
    'no_push_grace_days' => 7,
    'coolify_delete_timeout_minutes' => 10,
    'http_timeout_seconds' => 20,
];
