<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $rubrik }}{{ $game ? ' – '.$game->name : '' }}</title>
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-theme.css') }}">
    <style>
        /* System 1 för spelare: papper och bläck. Typsnitten från cc-theme.css, färgerna lokala. */
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
               background: #fbfaf6; color: #111111; font: 18px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-family: 'Silkscreen', 'IBM Plex Mono', monospace; font-weight: 400; font-size: 24px; line-height: 1.3; margin: 0 0 14px; }
        p { color: #111111; }
        a { color: #111111; font-weight: 700; text-underline-offset: 3px; }
        button { font: 600 16px/1 'IBM Plex Mono', ui-monospace, monospace; background: #fbfaf6; color: #111111;
                 border: 2px solid #111111; border-radius: 8px; padding: 10px 20px; cursor: pointer;
                 box-shadow: 0 0 0 2px #fbfaf6, 0 0 0 4px #111111; }
        button:hover { background: #e9e6df; }
        button:focus-visible, a:focus-visible { outline: 2px dashed #111111; outline-offset: 6px; }
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
