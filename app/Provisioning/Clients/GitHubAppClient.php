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

// GitHub App "ComputerCat Studio" (bilaga B). Appen har Administration:
// write och kan alltså radera repon. Klienten har därför ingen metod för
// DELETE och vägrar metoden i github(); TestCase vaktar hela sviten.
// Etapp 2: klienten läser filer vid en commit, sätter lättviktiga taggar, startar släpprepots
// flöde och läser och avbryter körningar; den flyttar eller raderar aldrig taggar.
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
    public function getFile(string $name, string $path, ?string $ref = null): ?array
    {
        $apiPath = '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path);
        if ($ref !== null) {
            $apiPath .= '?ref='.$this->commit($ref);
        }
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

    // Dispatch, körningar och avbrott når bara släpprepot och dess godkända flöde (plan R19:
    // appen kan annars starta vilket flöde som helst i vilket repo som helst). Spikflödet
    // release-ios-spike.yml ersätts av release-ios.yml i Task 10 och är därför inte tillåtet.
    public const RELEASE_REPO = 'computercat-release';

    public const RELEASE_WORKFLOWS = ['release-ios.yml'];

    private const MAX_INPUTS = 25;

    private const MAX_INPUT_LENGTH = 200;

    public const TAG_PATTERN = '/^v(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\z/';

    /**
     * type = 'commit' för en lättviktig tagg. 'tag' betyder annoterad tagg: sha är då taggobjektets,
     * inte commitens, och får inte jämföras med ett commit-SHA (Task 6 behandlar type !== 'commit' som
     * att taggen inte pekar på den commit som släpps).
     *
     * @return array{sha: string, type: string}|null
     */
    public function getTag(string $name, string $tag): ?array
    {
        $path = '/repos/'.$this->owner().'/'.$this->repoName($name).'/git/ref/tags/'.$this->tag($tag);
        $response = $this->github('GET', $path, null, [404]);
        if ($response->status() === 404) {
            return null;
        }

        $sha = $response->json('object.sha');
        $type = $response->json('object.type');
        if (! is_string($sha) || ! is_string($type)) {
            throw new ExternalApiException('GitHub', 'GET', $path, $response->status(), 'unexpected ref response (expected object.sha and object.type)');
        }

        return ['sha' => strtolower($sha), 'type' => $type];
    }

    // Lättviktig tagg (en ref direkt på commiten). Flyttas och raderas aldrig (plan R3, R4).
    public function createTag(string $name, string $tag, string $sha): void
    {
        $this->github('POST', '/repos/'.$this->owner().'/'.$this->repoName($name).'/git/refs', [
            'ref' => 'refs/tags/'.$this->tag($tag),
            'sha' => $this->commit($sha),
        ]);
    }

    /**
     * Spike A G1b: med return_run_details svarar GitHub 200 med körningens id.
     *
     * @param  array<string, string>  $inputs
     * @return array{run_id: int, html_url: string}
     */
    public function dispatchWorkflow(string $repo, string $workflow, string $ref, array $inputs): array
    {
        if (count($inputs) > self::MAX_INPUTS) {
            throw new \InvalidArgumentException('För många workflow-indata (högst '.self::MAX_INPUTS.').');
        }
        foreach ($inputs as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,49}\z/', $key) !== 1) {
                throw new \InvalidArgumentException('Ogiltigt namn på workflow-indata.');
            }
            if (! is_string($value) || strlen($value) > self::MAX_INPUT_LENGTH) {
                throw new \InvalidArgumentException("Workflow-indata '{$key}' måste vara en sträng på högst ".self::MAX_INPUT_LENGTH.' tecken.');
            }
        }
        $path = $this->releaseActions($repo, $workflow).'/dispatches';
        $response = $this->github('POST', $path, ['ref' => $this->branch($ref), 'inputs' => $inputs, 'return_run_details' => true]);

        $id = $response->json('workflow_run_id');
        $url = $response->json('html_url');
        if (! is_int($id) || $id <= 0 || ! is_string($url)) {
            throw new ExternalApiException('GitHub', 'POST', $path, $response->status(), 'dispatch response lacks workflow_run_id or html_url');
        }

        return ['run_id' => $id, 'html_url' => $url];
    }

    /** @return array{id: int, name: string, status: string, conclusion: ?string, html_url: string, head_sha: string} */
    public function workflowRun(string $repo, int $runId): array
    {
        $path = $this->releaseActions($repo).'/runs/'.$this->runId($runId);

        return $this->runFields($this->github('GET', $path)->json(), $path);
    }

    /** @return list<array{name: string, conclusion: ?string, failed_step: ?string}> */
    public function workflowRunJobs(string $repo, int $runId): array
    {
        $path = $this->releaseActions($repo).'/runs/'.$this->runId($runId).'/jobs?per_page=30';
        $jobs = $this->github('GET', $path)->json('jobs');
        if (! is_array($jobs)) {
            throw new ExternalApiException('GitHub', 'GET', $path, 200, 'response lacks jobs');
        }

        return array_values(array_map(function (array $job) {
            $failed = collect($job['steps'] ?? [])->first(fn ($s) => is_array($s) && in_array($s['conclusion'] ?? null, ['failure', 'timed_out', 'cancelled'], true));

            return [
                'name' => (string) ($job['name'] ?? '?'),
                'conclusion' => is_string($job['conclusion'] ?? null) ? $job['conclusion'] : null,
                'failed_step' => is_array($failed) ? (string) ($failed['name'] ?? '?') : null,
            ];
        }, array_filter($jobs, 'is_array')));
    }

    // Reserv när dispatch-svaret gick förlorat: run-name innehåller release_uuid (spike A G2).
    public function findWorkflowRun(string $repo, string $workflow, string $needle, \DateTimeInterface $since): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $needle) !== 1) {
            throw new \InvalidArgumentException('Uppslaget måste vara ett release_uuid.');
        }
        $path = $this->releaseActions($repo, $workflow).'/runs?'.http_build_query([
            'event' => 'workflow_dispatch',
            'created' => '>='.Carbon::instance($since)->utc()->format('Y-m-d\TH:i:s\Z'),
            'per_page' => 50,
        ], '', '&', PHP_QUERY_RFC3986);
        foreach ($this->github('GET', $path)->json('workflow_runs') ?? [] as $run) {
            if (is_array($run) && is_string($run['name'] ?? null) && str_contains($run['name'], $needle)) {
                return $this->runFields($run, $path);
            }
        }

        return null;
    }

    // 409 = körningen är redan avslutad; det är målet ändå.
    public function cancelWorkflowRun(string $repo, int $runId): void
    {
        $this->github('POST', $this->releaseActions($repo).'/runs/'.$this->runId($runId).'/cancel', null, [409]);
    }

    private function runFields(mixed $run, string $path): array
    {
        if (! is_array($run) || ! is_int($run['id'] ?? null) || ! is_string($run['status'] ?? null)) {
            throw new ExternalApiException('GitHub', 'GET', $path, 200, 'unexpected workflow run (expected id and status)');
        }

        return [
            'id' => $run['id'],
            'name' => (string) ($run['name'] ?? ''),
            'status' => $run['status'],
            'conclusion' => is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
            'html_url' => (string) ($run['html_url'] ?? ''),
            'head_sha' => strtolower((string) ($run['head_sha'] ?? '')),
        ];
    }

    // Bara släpprepot och ett godkänt flöde; allt annat avvisas innan någon token hämtas.
    private function releaseActions(string $repo, ?string $workflow = null): string
    {
        if ($repo !== self::RELEASE_REPO) {
            throw new \InvalidArgumentException('Actions nås bara i släpprepot.');
        }
        $path = '/repos/'.$this->owner().'/'.self::RELEASE_REPO.'/actions';
        if ($workflow !== null) {
            if (! in_array($workflow, self::RELEASE_WORKFLOWS, true)) {
                throw new \InvalidArgumentException('Flödet är inte godkänt för start.');
            }
            $path .= '/workflows/'.$workflow;
        }

        return $path;
    }

    private function tag(string $tag): string
    {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new \InvalidArgumentException("Ogiltig tagg: {$tag}");
        }

        return $tag;
    }

    private function commit(string $sha): string
    {
        if (preg_match('/^[0-9a-f]{40}\z/', $sha) !== 1) {
            throw new \InvalidArgumentException('Ogiltig ref: måste vara ett fullständigt commit-SHA.');
        }

        return $sha;
    }

    private function branch(string $ref): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,100}\z/', $ref) !== 1) {
            throw new \InvalidArgumentException("Ogiltig gren: {$ref}");
        }

        return $ref;
    }

    private function runId(int $id): int
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Ogiltigt körnings-id.');
        }

        return $id;
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
        // Spelrepon och släpprepot är skilda: computercat-* och reserverade namn är aldrig ett spelrepo.
        if (GameSlug::isReserved($name)) {
            throw new \InvalidArgumentException("Reserverat reponamn: {$name}");
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
