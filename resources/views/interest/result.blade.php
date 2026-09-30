<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $rubrik }}{{ $game ? ' – '.$game->name : '' }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #F5F7FF; color: #1A2350; font: 18px/1.6 system-ui, sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-size: 30px; line-height: 1.2; margin: 0 0 12px; }
        a { color: #4B5BFF; font-weight: 700; }
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
