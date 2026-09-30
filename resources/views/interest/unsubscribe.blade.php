<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Avregistrera{{ $game ? ' – '.$game->name : '' }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #F5F7FF; color: #1A2350; font: 18px/1.6 system-ui, sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-size: 30px; line-height: 1.2; margin: 0 0 12px; }
        button { font: inherit; font-weight: 700; padding: 12px 24px; border: 0; border-radius: 14px; background: #1A2350; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<main>
    @if ($finns)
        <h1>Avregistrera dig</h1>
        <p>Vill du att vi tar bort din adress? Du får då inga mail från oss om det här.</p>
        <form method="POST" action="{{ route('interest.unsubscribe.store', $token) }}">
            <button type="submit">Ta bort min adress</button>
        </form>
    @else
        <h1>Redan borttagen</h1>
        <p>Den här adressen finns inte hos oss, eller är redan avregistrerad.</p>
    @endif
</main>
</body>
</html>
