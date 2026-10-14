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
];
