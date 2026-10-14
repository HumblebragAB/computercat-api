<?php

namespace App\Provisioning\Clients;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\Redactor;
use App\Provisioning\Http\SendsApiRequests;
use App\Provisioning\ProvisioningConfig;
use App\Rules\GameSlug;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

// GitHub App "Humblebrag Spelstudio" (bilaga B). Appen har Administration:
// write och kan alltså radera repon. Klienten har därför ingen metod för
// DELETE och vägrar metoden i github(); TestCase vaktar hela sviten.
class GitHubAppClient
{
    use SendsApiRequests;

    public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH'];

    private const TOKEN_CACHE_KEY = 'provisioning:github:installation-token';

    protected function serviceName(): string
    {
        return 'GitHub';
    }

    protected function baseUrl(): string
    {
        return (string) config('provisioning.github.api_url');
    }

    // RS256, iss = client id, iat 60 s bakåt (klockdrift), exp 9 min (max 10).
    public function jwt(?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable;

        return Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->issuedBy((string) config('provisioning.github.client_id'))
            ->issuedAt($now->modify('-60 seconds'))
            ->expiresAt($now->modify('+9 minutes'))
            ->getToken(new Sha256, InMemory::file(ProvisioningConfig::privateKeyFile()))
            ->toString();
    }

    public function installationToken(): string
    {
        $installation = $this->installationId();
        $cacheKey = $this->tokenCacheKey();
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            try {
                $token = Crypt::decryptString($cached);
                Redactor::registerSecret($token);

                return $token;
            } catch (DecryptException) {
                Cache::forget($cacheKey);
            }
        }

        $path = "/app/installations/{$installation}/access_tokens";
        // Ingen body: utan repositories/permissions gäller token installationens
        // alla repon och rättigheter (bilaga B §2).
        $response = $this->sendRequest($this->request($this->jwt()), 'POST', $path);
        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');
        if (! is_string($token) || $token === '' || ! is_string($expiresAt)) {
            throw new ExternalApiException('GitHub', 'POST', $path, $response->status(), 'response lacks token or expires_at');
        }

        Redactor::registerSecret($token);
        $expires = Carbon::parse($expiresAt);
        // En redan utgången token ger annars en 401 i nästa anrop; felet ska synas här.
        if (! $expires->isFuture()) {
            throw new ExternalApiException('GitHub', 'POST', $path, $response->status(), 'installation token already expired (expires_at is in the past)');
        }
        // Cachas bara om den gäller längre än marginalen (5 min); annars används den en gång.
        $until = $expires->copy()->subMinutes(5);
        if ($until->isFuture()) {
            // Krypterad i cachen: cachetabellen är inte en hemlighetslagring.
            Cache::put($cacheKey, Crypt::encryptString($token), $until);
        }

        return $token;
    }

    public function forgetInstallationToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    // Nyckeln bär installationen: en token för en annan installation återanvänds aldrig.
    private function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY.':'.$this->installationId();
    }

    private function installationId(): string
    {
        $installation = (string) config('provisioning.github.installation_id');
        if (preg_match('/^\d+\z/', $installation) !== 1) {
            throw new \InvalidArgumentException('GITHUB_APP_INSTALLATION_ID måste vara ett heltal.');
        }

        return $installation;
    }

    public function getRepository(string $name): ?array
    {
        $response = $this->github('GET', '/repos/'.$this->owner().'/'.$this->repoName($name), null, [404]);

        return $response->status() === 404 ? null : $response->json();
    }

    // Bilaga B §6: POST /orgs/{org}/repos. auto_init ger en första commit med
    // README, så att Contents-API:t kan skriva README och CLAUDE.md direkt.
    public function createRepository(string $name, string $description): ?array
    {
        $path = '/orgs/'.$this->owner().'/repos';
        $response = $this->github('POST', $path, [
            'name' => $this->repoName($name),
            'description' => $description,
            // Standardvärdet är false (publikt repo); skickas alltid uttryckligen.
            'private' => true,
            'auto_init' => true,
        ], [422]);

        // Bilaga B §2: det är inte dokumenterat att en redan utfärdad token
        // täcker ett repo som skapats efteråt. Nästa anrop tar därför en ny.
        $this->forgetInstallationToken();

        if ($response->status() !== 422) {
            return $response->json();
        }

        // Bara "namnet finns redan" betyder null (bodyns form verifieras i spike S12).
        // Alla andra 422 är fel som ska synas, inte en upptagen slug.
        if (str_contains(strtolower($response->body()), 'already exists')) {
            return null;
        }

        throw new ExternalApiException('GitHub', 'POST', $path, 422, Redactor::body($response->body()));
    }

    /** @return array{sha: string, text: string}|null */
    public function getFile(string $name, string $path): ?array
    {
        $apiPath = '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path);
        $response = $this->github('GET', $apiPath, null, [404]);
        if ($response->status() === 404) {
            return null;
        }

        $sha = $response->json('sha');
        $content = $response->json('content');
        if (! is_string($sha) || ! is_string($content) || $response->json('encoding') !== 'base64') {
            throw new ExternalApiException('GitHub', 'GET', $apiPath, $response->status(), 'unexpected contents response (expected sha, content, encoding=base64)');
        }

        $text = base64_decode(str_replace(["\n", "\r"], '', $content), true);
        if ($text === false) {
            throw new ExternalApiException('GitHub', 'GET', $apiPath, $response->status(), 'content is not valid base64');
        }

        return ['sha' => $sha, 'text' => $text];
    }

    // sha krävs för att skriva över en befintlig fil och utelämnas för en ny (spike S11).
    public function putFile(string $name, string $path, string $text, ?string $sha, string $message): void
    {
        $body = [
            'message' => $message,
            'content' => base64_encode($text),
            // Spelrepona har bara main; Coolify bygger main.
            'branch' => 'main',
        ];
        if ($sha !== null) {
            $body['sha'] = $sha;
        }

        $this->github('PUT', '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path), $body);
    }

    public function archiveRepository(string $name): array
    {
        return $this->github('PATCH', '/repos/'.$this->owner().'/'.$this->repoName($name), ['archived' => true])->json();
    }

    private function github(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \LogicException("GitHubAppClient skickar aldrig {$method}.");
        }

        return $this->sendRequest($this->request($this->installationToken()), $method, $path, $body, $accept);
    }

    private function request(string $bearer): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($bearer)
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }

    private function owner(): string
    {
        return ProvisioningConfig::githubOwner();
    }

    // Reponamn hamnar i URL:er; bara slugformatet släpps igenom.
    private function repoName(string $name): string
    {
        if (preg_match(GameSlug::PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Ogiltigt reponamn: {$name}");
        }

        return $name;
    }

    private function filePath(string $path): string
    {
        if (preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*\z#', $path) !== 1 || str_contains($path, '..')) {
            throw new \InvalidArgumentException("Ogiltig sökväg: {$path}");
        }

        return $path;
    }
}
