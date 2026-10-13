<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $rubrik }}{{ $game ? ' – '.$game->name : '' }}</title>
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-theme.css') }}">
    <style>
        /* Ljus variant av Fosfor för spelare. Typsnittet från cc-theme.css, färgerna lokala. */
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
               background: #fbf7ee; color: #2a1f0c; font: 18px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 600; font-size: 26px; line-height: 1.25;
             letter-spacing: .01em; margin: 0 0 12px; }
        h1::before { content: '> ' / ''; color: #8a5a00; }
        p { color: #2a1f0c; }
        a { color: #8a5a00; font-weight: 700; text-underline-offset: 3px; }
        button { font: 600 15px/1 'IBM Plex Mono', ui-monospace, monospace; letter-spacing: .06em; background: #8a5a00;
                 color: #fbf7ee; border: 0; padding: 12px 20px; cursor: pointer; }
        button:hover { background: #6f4800; }
        button:focus-visible, a:focus-visible { outline: 2px solid #8a5a00; outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $rubrik }}</h1>
    <p>{{ $text }}</p>
    @if ($game && filled($game->settings['site_url'] ?? null))
        <p><a href="{{ $game->settings['site_url'] }}">Tillbaka till {{ $game->name }}</a></p>
    @endif
</main>
</body>
</html>
