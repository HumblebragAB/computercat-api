{{--
    Ersätter laravel/mcp:s mcp::authorize (vendor/laravel/mcp/resources/views/mcp/authorize.blade.php).
    Paketets vy laddar resources/css/app.css via @vite, och produktionen bygger inga
    assets, så den ger 500. Här: inline-CSS, inga externa typsnitt eller CDN:er.
    Formulären (fält, CSRF, approve POST / deny DELETE) är desamma som i paketets vy.
    Klientens logo_uri och client_uri visas inte: de sätts av vem som helst via
    dynamisk registrering och hör inte hemma på godkännandesidan.
    Redirect-värden visas: den adress Passport skickar koden till. Passport har
    redan kontrollerat redirect_uri mot klientens registrerade adresser, och
    dynamisk registrering godtar bara claude.ai och loopback (localhost, 127.0.0.1,
    [::1]) för Claude Code. En loopback-redirect lämnar koden på användarens egen
    dator, och PKCE krävs för publika klienter.
    Alla dynamiska värden escapas med {{ }}.
    Temat (typsnitt och tokens) kommer från public/css/cc-theme.css på samma domän.
--}}
@php
    $redirectUri = $request->query('redirect_uri');
    $redirectUri = is_string($redirectUri) && $redirectUri !== '' ? $redirectUri : ($client->redirect_uris[0] ?? '');
    $redirectHost = parse_url($redirectUri, PHP_URL_HOST) ?: 'okänd adress';
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Godkänn åtkomst - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-theme.css') }}">
    <style>
        /* System 1: Mac-dialog. Rubriken (h1) är fönstrets randiga titellist, så markupen är orörd.
           Fallbacks i var() gör att sidan blir rätt även utan cc-theme.css. */
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background-color: var(--cc-desk, #e9e6df); color: var(--cc-ink, #111111);
               background-image: radial-gradient(var(--cc-dot, #b9b4a8) 1px, transparent 1px); background-size: 4px 4px;
               font-family: var(--cc-font, ui-monospace, monospace); font-size: 14px; line-height: 1.6; }
        .card { width: 100%; max-width: 28rem; background: var(--cc-paper, #fbfaf6); border: 2px solid var(--cc-ink, #111111);
                box-shadow: 4px 4px 0 var(--cc-ink, #111111); padding: 0 0 22px; }
        /* Bara sidmarginaler; elementens egna vertikala marginaler står kvar. */
        .card > :not(h1) { margin-left: 22px; margin-right: 22px; }
        h1 { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 4px 10px; border-bottom: 2px solid var(--cc-ink, #111111);
             font-family: var(--cc-font-display, var(--cc-font, monospace)); font-weight: 400; font-size: 13px; line-height: 1.4;
             text-align: center; overflow-wrap: anywhere; }
        h1::before, h1::after { content: ''; flex: 1 1 24px; min-width: 24px; height: 11px;
             background-image: repeating-linear-gradient(0deg, var(--cc-ink, #111111) 0 1px, transparent 1px 3px); }
        p { margin: 0; }
        .muted { color: var(--cc-muted, #55524b); font-size: .85rem; }
        .center { text-align: center; }
        .box { border: 1px dashed var(--cc-ink, #111111); padding: 12px 16px; margin: 20px 0 16px; }
        .email { font-weight: 600; overflow-wrap: anywhere; }
        ul { margin: 8px 0 0; padding-left: 20px; }
        .actions { display: flex; justify-content: flex-end; gap: 16px; margin-top: 24px; }
        .actions form { flex: 0 0 auto; }
        button { min-width: 7.5rem; height: 36px; padding: 0 16px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer;
                 background: var(--cc-paper, #fbfaf6); color: var(--cc-ink, #111111); border: 2px solid var(--cc-ink, #111111); }
        button:hover { background: var(--cc-desk, #e9e6df); }
        button:disabled { opacity: .6; cursor: default; }
        button:focus-visible { outline: 2px dashed var(--cc-ink, #111111); outline-offset: 6px; }
        /* Standardknappen har dubbelram, som på Macen. */
        .primary { box-shadow: 0 0 0 2px var(--cc-paper, #fbfaf6), 0 0 0 4px var(--cc-ink, #111111); }
    </style>
</head>
<body>
<main class="card">
    <h1>Godkänn {{ $client->name }}</h1>
    <p class="muted center">Programmet får använda Computercats MCP-server i ditt namn.</p>

    <div class="box">
        <p class="muted">Inloggad som</p>
        <p class="email">{{ $user->email }}</p>
        <p class="muted" style="margin-top: 8px">Svaret skickas till</p>
        <p class="email">{{ $redirectHost }}</p>
    </div>

    <p class="muted" style="margin-bottom: 16px">Godkänn bara om du själv just startade kopplingen i claude.ai eller Claude Code.</p>

    @if (count($scopes) > 0)
        <p><strong>Behörigheter</strong></p>
        <ul>
            @foreach ($scopes as $scope)
                <li class="muted">{{ $scope->description }}</li>
            @endforeach
        </ul>
    @endif

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}" id="denyForm">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="secondary">Avbryt</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary" id="authorizeButton">Godkänn</button>
        </form>
    </div>
</main>

<script>
    // Som paketets vy: stäng fönstret när redirecten tillbaka till klienten har skett.
    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('authorizeButton');

        document.getElementById('authorizeForm').addEventListener('submit', function () {
            button.disabled = true;
            button.textContent = 'Godkänner…';

            setTimeout(function () {
                var check = setInterval(function () {
                    if (!window.location.href.includes('/oauth/authorize') ||
                        window.location.search.includes('code=') ||
                        window.location.search.includes('error=')) {
                        clearInterval(check);
                        window.close();
                    }
                }, 100);

                setTimeout(function () {
                    clearInterval(check);
                    window.close();
                }, 5000);
            }, 200);
        });

        document.getElementById('denyForm').addEventListener('submit', function () {
            setTimeout(function () { window.close(); }, 200);
        });
    });
</script>
</body>
</html>
