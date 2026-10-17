<?php

use App\Matches\Rules\RevealRules;

// Matcher: vilka regeluppsättningar som finns och vilka spel (slug) som får använda dem.
// 'invite' väljer inbjudningstyp (App\Matches\InviteTokens): 'token' (standard när nyckeln
// saknas, 43 tecken för länk/QR) eller 'code' (4 tecken att skriva in för hand).
return [
    'rulesets' => [
        'reveal' => ['class' => RevealRules::class, 'games' => ['reveal'], 'invite' => 'code'],
    ],
];
