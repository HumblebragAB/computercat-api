<?php

use App\Matches\Rules\RevealRules;

// Matcher: vilka regeluppsättningar som finns och vilka spel (slug) som får använda dem.
return [
    'rulesets' => [
        'reveal' => ['class' => RevealRules::class, 'games' => ['reveal']],
    ],
];
