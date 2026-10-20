<?php

// Vidarebefordran av appförsäljning till Pejl (intäktskällor). Nyckeln läses bara ur miljön.
return [
    'api_url' => env('PEJL_API_URL'),
    'api_key' => env('PEJL_API_KEY'),
    'timezone' => 'Europe/Stockholm',

    // Riksbankens SWEA: GET {base_url}/Observations/{serie}/{från}/{till} -> [{date, value}], 204 utan bankdag,
    // 429 efter fem anrop i minuten. value = SEK per en enhet valuta.
    'riksbank' => [
        'base_url' => env('PEJL_RIKSBANK_BASE_URL', 'https://api.riksbank.se/swea/v1'),
        // ASSUMED — verify against SWEA /Series (seriekoden är inte verifierad mot live-API:t)
        'series' => ['USD' => 'SEKUSDPMI'],
        'lookback_days' => 7,
        'bounds' => ['USD' => [6.0, 16.0]],
        'timeout' => 10,
    ],
];
