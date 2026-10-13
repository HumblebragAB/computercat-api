{{--
    Ersätter laravel/mcp:s mcp::authorize (vendor/laravel/mcp/resources/views/mcp/authorize.blade.php).
    Paketets vy laddar resources/css/app.css via @vite, och produktionen bygger inga
    assets, så den ger 500. Här: inline-CSS, inga externa typsnitt eller CDN:er.
    Formulären (fält, CSRF, approve POST / deny DELETE) är desamma som i paketets vy.
    Klientens logo_uri och client_uri visas inte: de sätts av vem som helst via
    dynamisk registrering och hör inte hemma på godkännandesidan.
    Redirect-värden visas: den adress Passport skickar koden till. Passport har
    redan kontrollerat redirect_uri mot klientens registrerade adresser, och
    dynamisk registrering godtar bara claude.ai.
    Alla dynamiska värden escapas med {{ }}.
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
    <style>
        :root { --bg: #f5f5f5; --card: #fff; --text: #171717; --muted: #666; --border: #e5e5e5; --primary: #171717; --on-primary: #fff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0a0a0a; --card: #171717; --text: #f5f5f5; --muted: #a3a3a3; --border: #2e2e2e; --primary: #f5f5f5; --on-primary: #171717; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.5; }
        .card { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; }
        h1 { font-size: 1.4rem; margin: 0 0 8px; text-align: center; }
        p { margin: 0; }
        .muted { color: var(--muted); font-size: 0.9rem; }
        .center { text-align: center; }
        .box { border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin: 20px 0 16px; }
        .email { font-weight: 600; overflow-wrap: anywhere; }
        ul { margin: 8px 0 0; padding-left: 20px; }
        .actions { display: flex; gap: 12px; margin-top: 24px; }
        .actions form { flex: 1; }
        button { width: 100%; height: 40px; border-radius: 6px; font: inherit; font-weight: 500; cursor: pointer; }
        button:disabled { opacity: 0.6; cursor: default; }
        .secondary { background: transparent; color: var(--text); border: 1px solid var(--border); }
        .primary { background: var(--primary); color: var(--on-primary); border: 1px solid var(--primary); }
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

    <p class="muted" style="margin-bottom: 16px">Godkänn bara om du själv just startade kopplingen i claude.ai.</p>

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
