<?php

namespace App\Releases\AppStore;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\Redactor;
use App\Provisioning\Http\SendsApiRequests;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

// App Store Connect för släppflödet (spike B; fältnamn ur Apples OpenAPI 4.5.1, se bilagan).
// Hotmodell (Task 1): teamnyckeln (App Manager) kan mer än etapp 2 behöver, så klienten har bara
// de metoder som finns här och skickar aldrig DELETE eller PATCH. Nyckeln läses bara av
// InMemory::file; JWT:n gäller 15 min och maskeras av Redactor (eyJ…). Id:n som går in i en
// sökväg valideras. 4xx/5xx loggas med request och response, redigerat (SendsApiRequests).
final class AppStoreConnectApi
{
    use SendsApiRequests;

    public const ALLOWED_METHODS = ['GET', 'POST'];

    private const ID = '/^[A-Za-z0-9-]{1,64}\z/';

    private ?string $token = null;

    private ?DateTimeImmutable $tokenExpires = null;

    protected function serviceName(): string
    {
        return 'App Store Connect';
    }

    protected function baseUrl(): string
    {
        return (string) config('releases.app_store.api_url');
    }

    // ES256; kid = nyckel-id; iss = issuer; aud = appstoreconnect-v1 (sträng); exp högst 20 min.
    public function jwt(?DateTimeImmutable $now = null): string
    {
        AppStoreConfig::assertKeyReady();
        $now ??= new DateTimeImmutable;

        return Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', AppStoreConfig::keyId())
            ->issuedBy(AppStoreConfig::issuerId())
            ->issuedAt($now)
            ->expiresAt($now->modify('+15 minutes'))
            ->permittedFor('appstoreconnect-v1')
            ->getToken(new Sha256, InMemory::file(AppStoreConfig::privateKeyFile()))
            ->toString();
    }

    /** @return array{id: string, identifier: string}|null */
    public function findBundleId(string $identifier): ?array
    {
        foreach ($this->list('/v1/bundleIds', ['filter[identifier]' => $identifier, 'filter[platform]' => 'IOS', 'limit' => 200]) as $row) {
            if (($row['attributes']['identifier'] ?? null) === $identifier) {
                return ['id' => $this->id($row), 'identifier' => $identifier];
            }
        }

        return null;
    }

    /** @return array{id: string, identifier: string} */
    public function createBundleId(string $identifier, string $name): array
    {
        $row = $this->create('/v1/bundleIds', ['type' => 'bundleIds', 'attributes' => ['identifier' => $identifier, 'name' => $name, 'platform' => 'IOS']]);

        return ['id' => $this->id($row), 'identifier' => (string) ($row['attributes']['identifier'] ?? $identifier)];
    }

    /** @return array{id: string, name: string, sku: string, bundle_id: string}|null */
    public function findApp(string $bundleId): ?array
    {
        foreach ($this->list('/v1/apps', ['filter[bundleId]' => $bundleId, 'fields[apps]' => 'name,bundleId,sku,primaryLocale', 'limit' => 10]) as $row) {
            if (($row['attributes']['bundleId'] ?? null) === $bundleId) {
                return ['id' => $this->id($row), 'name' => (string) ($row['attributes']['name'] ?? ''), 'sku' => (string) ($row['attributes']['sku'] ?? ''), 'bundle_id' => $bundleId];
            }
        }

        return null;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string}|null */
    public function findProfile(string $name): ?array
    {
        foreach ($this->list('/v1/profiles', ['filter[name]' => $name, 'filter[profileType]' => 'IOS_APP_STORE', 'fields[profiles]' => 'name,profileState,expirationDate,uuid', 'limit' => 10]) as $row) {
            if (($row['attributes']['name'] ?? null) === $name) {
                return $this->profileFields($row);
            }
        }

        return null;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string} */
    public function createProfile(string $name, string $bundleResourceId, string $certificateId): array
    {
        return $this->profileFields($this->create('/v1/profiles', [
            'type' => 'profiles',
            'attributes' => ['name' => $name, 'profileType' => 'IOS_APP_STORE'],
            'relationships' => [
                'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => $this->pathId($bundleResourceId)]],
                'certificates' => ['data' => [['type' => 'certificates', 'id' => $this->pathId($certificateId)]]],
            ],
        ]));
    }

    // profileContent hämtas aldrig hit; det gör bara sign-jobbet i släpprepot.
    /** @return array{id: string, uuid: string, state: string, expires_at: string}|null */
    public function profile(string $id): ?array
    {
        $json = $this->get('/v1/profiles/'.$this->pathId($id), ['fields[profiles]' => 'name,profileState,expirationDate,uuid'], [404]);

        return $json === null ? null : $this->profileFields($json['data'] ?? []);
    }

    /** @return array{id: string, name: string}|null */
    public function findBetaGroup(string $appId, string $name): ?array
    {
        foreach ($this->list('/v1/betaGroups', ['filter[app]' => $this->pathId($appId), 'filter[name]' => $name, 'filter[isInternalGroup]' => 'true', 'limit' => 50]) as $row) {
            if (($row['attributes']['name'] ?? null) === $name && ($row['attributes']['isInternalGroup'] ?? null) === true) {
                return ['id' => $this->id($row), 'name' => $name];
            }
        }

        return null;
    }

    /** @return array{id: string, name: string} */
    public function createInternalBetaGroup(string $appId, string $name): array
    {
        $row = $this->create('/v1/betaGroups', [
            'type' => 'betaGroups',
            // R7: samma läge som spike B9; Computercat kontrollerar ändå att varje bygge finns i gruppen.
            'attributes' => ['name' => $name, 'isInternalGroup' => true, 'hasAccessToAllBuilds' => true],
            'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => $this->pathId($appId)]]],
        ]);

        return ['id' => $this->id($row), 'name' => $name];
    }

    // R18: e-postadressen läses bara här och registreras som hemlighet innan den används i något
    // anrop, så att den maskeras i loggade URL:er, kroppar och fel (rå och URL-kodad).
    public function userEmail(string $userId): string
    {
        $json = $this->get('/v1/users/'.$this->pathId($userId), ['fields[users]' => 'username']);
        $email = $json['data']['attributes']['username'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ExternalApiException($this->serviceName(), 'GET', '/v1/users/{id}', 200, 'user without a valid username');
        }
        self::protect($email);

        return $email;
    }

    /** @return array{id: string}|null */
    public function findBetaTester(string $email, ?string $groupId = null): ?array
    {
        self::protect($email);
        $query = ['filter[email]' => $email, 'fields[betaTesters]' => 'email', 'limit' => 10];
        if ($groupId !== null) {
            $query['filter[betaGroups]'] = $this->pathId($groupId);
        }
        foreach ($this->list('/v1/betaTesters', $query) as $row) {
            if (strcasecmp((string) ($row['attributes']['email'] ?? ''), $email) === 0) {
                return ['id' => $this->id($row)];
            }
        }

        return null;
    }

    /** @return array{id: string} */
    public function createBetaTester(string $email, string $groupId): array
    {
        self::protect($email);
        $row = $this->create('/v1/betaTesters', [
            'type' => 'betaTesters',
            'attributes' => ['email' => $email],
            'relationships' => ['betaGroups' => ['data' => [['type' => 'betaGroups', 'id' => $this->pathId($groupId)]]]],
        ]);

        return ['id' => $this->id($row)];
    }

    private static function protect(string $email): void
    {
        Redactor::registerSecret($email);
        Redactor::registerSecret(rawurlencode($email));
    }

    /** @param list<string> $testerIds */
    public function addTestersToGroup(string $groupId, array $testerIds): void
    {
        $this->send('POST', '/v1/betaGroups/'.$this->pathId($groupId).'/relationships/betaTesters', [
            'data' => array_map(fn (string $id) => ['type' => 'betaTesters', 'id' => $this->pathId($id)], array_values($testerIds)),
        ]);
    }

    /** @return array{id: string, state: string, version: string, expired: bool}|null */
    public function findBuild(string $appId, string $marketingVersion, int $buildNumber): ?array
    {
        $rows = $this->list('/v1/builds', [
            'filter[app]' => $this->pathId($appId),
            'filter[version]' => (string) $buildNumber,
            'filter[preReleaseVersion.version]' => $marketingVersion,
            'fields[builds]' => 'version,processingState,expired,uploadedDate',
            'limit' => 2,
        ]);
        foreach ($rows as $row) {
            if (($row['attributes']['version'] ?? null) === (string) $buildNumber) {
                return [
                    'id' => $this->id($row),
                    'state' => (string) ($row['attributes']['processingState'] ?? ''),
                    'version' => (string) $buildNumber,
                    'expired' => (bool) ($row['attributes']['expired'] ?? false),
                ];
            }
        }

        return null;
    }

    public function buildIsInGroup(string $groupId, string $buildId): bool
    {
        return $this->list('/v1/builds', ['filter[betaGroups]' => $this->pathId($groupId), 'filter[id]' => $this->pathId($buildId), 'fields[builds]' => 'version', 'limit' => 1]) !== [];
    }

    public function addBuildToGroup(string $groupId, string $buildId): void
    {
        $this->send('POST', '/v1/betaGroups/'.$this->pathId($groupId).'/relationships/builds', ['data' => [['type' => 'builds', 'id' => $this->pathId($buildId)]]]);
    }

    /** @return list<array> */
    private function list(string $path, array $query): array
    {
        $data = $this->get($path, $query)['data'] ?? null;
        if (! is_array($data)) {
            throw new ExternalApiException($this->serviceName(), 'GET', $path, 200, 'response lacks a data list');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function create(string $path, array $data): array
    {
        $response = $this->send('POST', $path, ['data' => $data]);
        $row = $response->json('data');
        if (! is_array($row)) {
            throw new ExternalApiException($this->serviceName(), 'POST', $path, $response->status(), 'response lacks data');
        }

        return $row;
    }

    /** @param list<int> $accept */
    private function get(string $path, array $query, array $accept = []): ?array
    {
        $response = $this->send('GET', $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, $accept);
        if (in_array($response->status(), $accept, true)) {
            return null;
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new ExternalApiException($this->serviceName(), 'GET', $path, $response->status(), 'response is not JSON');
        }

        return $json;
    }

    /** @param list<int> $accept */
    private function send(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \LogicException("AppStoreConnectApi skickar aldrig {$method}.");
        }

        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->acceptJson()
            ->timeout((int) config('releases.app_store.http_timeout_seconds'))
            ->connectTimeout(5);
    }

    private function token(): string
    {
        $now = new DateTimeImmutable;
        if ($this->token === null || $this->tokenExpires === null || $this->tokenExpires <= $now->modify('+2 minutes')) {
            $this->token = $this->jwt($now);
            $this->tokenExpires = $now->modify('+15 minutes');
        }

        return $this->token;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string} */
    private function profileFields(array $row): array
    {
        return [
            'id' => $this->id($row),
            'uuid' => (string) ($row['attributes']['uuid'] ?? ''),
            'state' => (string) ($row['attributes']['profileState'] ?? ''),
            'expires_at' => (string) ($row['attributes']['expirationDate'] ?? ''),
        ];
    }

    private function id(array $row): string
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw new ExternalApiException($this->serviceName(), 'GET', '(resource)', 200, 'resource without a valid id');
        }

        return $id;
    }

    private function pathId(string $id): string
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('Ogiltigt App Store Connect-id.');
        }

        return $id;
    }
}
