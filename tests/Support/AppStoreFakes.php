<?php

namespace Tests\Support;

// Konfiguration och svar för App Store Connect i testerna. Formen följer Apples OpenAPI 4.5.1
// (data: {type, id, attributes}); id:n och namn är spike B:s där de finns (bilagan). Nyckeln är
// en P-256-nyckel som skapas här; de riktiga värdena finns bara i prod.
final class AppStoreFakes
{
    public const API = 'https://api.appstoreconnect.apple.com';

    public const KEY_FILE = 'framework/testing/asc-release-test.p8';

    public const KEY_ID = 'TESTKEY001';

    public const ISSUER = '00000000-0000-4000-8000-000000000000';

    public const CERT = 'TESTCERT01';

    public const TESTER_IDS = ['00000000-0000-4000-8000-0000000000a1', '00000000-0000-4000-8000-0000000000a2'];

    public const TESTER_EMAILS = ['00000000-0000-4000-8000-0000000000a1' => 'erik@example.test', '00000000-0000-4000-8000-0000000000a2' => 'mattias@example.test'];

    public const BUNDLE_RESOURCE = 'WN54V4BM5D';

    public const APP = '6823067635';

    public const PROFILE = 'PROFTEST01';

    public const GROUP = '0f1e2d3c-0000-4000-8000-000000000001';

    private static ?array $keyPair = null;

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($key, $private);
            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$keyPair;
    }

    public static function configure(int $mode = 0600): void
    {
        $path = storage_path(self::KEY_FILE);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, self::keyPair()['private']);
        chmod($path, $mode);

        config([
            'releases.app_store.key_id' => self::KEY_ID,
            'releases.app_store.issuer_id' => self::ISSUER,
            'releases.app_store.private_key_path' => self::KEY_FILE,
            'releases.app_store.certificate_id' => self::CERT,
            'releases.app_store.tester_user_ids' => self::TESTER_IDS,
        ]);
    }

    public static function one(array $resource): array
    {
        return ['data' => $resource, 'links' => ['self' => self::API.'/v1/x']];
    }

    public static function many(array ...$resources): array
    {
        return ['data' => array_values($resources), 'links' => ['self' => self::API.'/v1/x'], 'meta' => ['paging' => ['total' => count($resources), 'limit' => 200]]];
    }

    public static function bundleId(string $identifier, string $id = self::BUNDLE_RESOURCE): array
    {
        return ['type' => 'bundleIds', 'id' => $id, 'attributes' => ['identifier' => $identifier, 'name' => 'ComputerCat Kattsvep', 'platform' => 'IOS', 'seedId' => 'CUDL7PSFD2']];
    }

    public static function app(string $bundleId, string $id = self::APP, string $name = 'Kattsvep', string $sku = 'kattsvep'): array
    {
        return ['type' => 'apps', 'id' => $id, 'attributes' => ['name' => $name, 'bundleId' => $bundleId, 'sku' => $sku, 'primaryLocale' => 'sv']];
    }

    public static function profile(string $id = self::PROFILE, string $state = 'ACTIVE', ?string $expires = null, string $name = 'ComputerCat kattsvep App Store'): array
    {
        return ['type' => 'profiles', 'id' => $id, 'attributes' => [
            'name' => $name, 'profileType' => 'IOS_APP_STORE', 'profileState' => $state,
            'uuid' => '6b1c2a4e-0000-4000-8000-00000000abcd', 'expirationDate' => $expires ?? now()->addYear()->toIso8601String(),
        ]];
    }

    public static function betaGroup(string $id = self::GROUP, string $name = 'ComputerCat intern', bool $internal = true): array
    {
        return ['type' => 'betaGroups', 'id' => $id, 'attributes' => ['name' => $name, 'isInternalGroup' => $internal, 'hasAccessToAllBuilds' => true]];
    }

    /** GET /v1/users/{id} (User: username är inloggningens e-postadress; allAppsVisible enligt User.Attributes). */
    public static function user(string $id, string $email, bool $allAppsVisible = true): array
    {
        return ['type' => 'users', 'id' => $id, 'attributes' => ['username' => $email, 'firstName' => 'Test', 'lastName' => 'Testare', 'roles' => ['APP_MANAGER'], 'allAppsVisible' => $allAppsVisible]];
    }

    public static function betaTester(string $email, string $id): array
    {
        return ['type' => 'betaTesters', 'id' => $id, 'attributes' => ['email' => $email, 'inviteType' => 'EMAIL', 'state' => 'INVITED']];
    }

    public static function build(string $id, int $buildNumber, string $state, bool $expired = false): array
    {
        return ['type' => 'builds', 'id' => $id, 'attributes' => ['version' => (string) $buildNumber, 'processingState' => $state, 'expired' => $expired, 'uploadedDate' => now()->toIso8601String()]];
    }

    /** Felsvar enligt ErrorResponse (id, status, code, title, detail). */
    public static function error(int $status, string $code, string $detail): array
    {
        return ['errors' => [['id' => 'err-1', 'status' => (string) $status, 'code' => $code, 'title' => 'Error', 'detail' => $detail]]];
    }
}
