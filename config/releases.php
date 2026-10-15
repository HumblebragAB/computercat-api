<?php

// Släppflödet (spec 2026-10-14, delprojekt 4). Värdena hamnar aldrig i verktygssvar.
return [
    // Global brytare. Allt utom ett sant värde stänger, så att en felstavning stänger i stället för att öppna.
    'enabled' => filter_var(env('RELEASES_ENABLED', true), FILTER_VALIDATE_BOOL),

    // Ett släpp i awaiting_approval eller requested utan beslut/byggstart blir cancelled efter så här många dygn.
    'ttl_days' => 7,

    // building/processing längre än så här larmar nattkontrollen.
    'stuck_minutes' => 120,

    // Öppna engångssteg äldre än så här påminns det om.
    'setup_reminder_days' => 7,

    // Hur många byggen pollningen läser per spel (Coolifys lista visar bara de senaste).
    'demo_poll_take' => 20,

    // Steg som promote_release får skapa släpp för (R1). Produktionsstegen är stängda i 1a:
    // etapp 1b lägger till web_production, etapp 2 app_store, etapp 3 play_production.
    'buildable_stages' => ['testflight', 'play_internal'],

    // Etapp 2 (TestFlight). Nyckel-id, issuer och certifikat-id är inte hemliga men hör till
    // miljön (Eriks beslut 2026-10-15). Den privata nyckeln är en fil i storage/app/private (läge 600).
    'app_store' => [
        'api_url' => 'https://api.appstoreconnect.apple.com',
        'key_id' => env('ASC_RELEASE_KEY_ID'),
        'issuer_id' => env('ASC_RELEASE_ISSUER_ID'),
        // Relativ till storage/.
        'private_key_path' => env('ASC_RELEASE_PRIVATE_KEY_PATH', 'app/private/asc-release.p8'),
        'certificate_id' => env('ASC_RELEASE_CERTIFICATE_ID'),
        'beta_group' => 'ComputerCat intern',
        // Interna testare måste vara användare i App Store Connect (spike B9). Användar-id, inte e-post
        // (Eriks beslut 2026-10-15); e-postadressen hämtas med GET /v1/users/{id} och loggas aldrig.
        'tester_user_ids' => [
            '02c9745a-c3d8-423e-9d54-2bec1cdab856',
            '57b3db4f-6777-41af-a555-bd0b32ed9f45',
            'd64532b0-55e1-402e-8854-16468c523d07',
            'e6939074-f8cc-48f0-b0c6-a510d6b2d4ba',
        ],
        // Per anrop. Jobben gör högst fyra anrop per körning och har timeout under köns retry_after (90 s).
        'http_timeout_seconds' => 15,
    ],

    // Släpprepots flöde. Computercat startar det alltid på släpprepots egen huvudgren.
    'ios_workflow' => [
        'repo' => 'computercat-release',
        'workflow' => 'release-ios.yml',
        'ref' => 'main',
    ],

    'timeouts' => [
        // Flödets jobb har timeout-minutes 10 + 40 + 30 = 80; tio minuters kö utöver det (R11).
        'github_run_minutes' => 90,
        'apple_processing_minutes' => 90,
        // Så länge letas en körning upp via run-name när dispatch-svaret gick förlorat.
        'dispatch_lookup_minutes' => 3,
    ],

    // Svepet försöker avbryta körningen för ett fällt släpp så här många gånger (var femte minut,
    // alltså ungefär en timme) när GitHub inte svarade när släppet fälldes; sedan larm. Lika länge
    // letar svepet via run-name efter en körning för ett fällt släpp utan känd körning (inget larm).
    'cancel_retries' => 12,

    'poll_seconds' => [
        'github' => 30,
        'apple' => 120,
    ],

    // Ett TestFlight-släpp i requested längre än så här larmar nattkontrollen (jobbet har inte startat).
    'start_stuck_minutes' => 30,
];
