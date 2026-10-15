<?php

namespace App\Releases\AppStore;

// Läser releases.app_store. Inga värden lämnar klassen i fel eller loggar; bara env-namn.
final class AppStoreConfig
{
    private const KEY_ID = '/^[A-Z0-9]{10}\z/';

    private const ISSUER = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** @return list<string> env-namn som saknas eller har fel form */
    public static function missing(): array
    {
        $missing = self::missingKey();
        if (preg_match(self::KEY_ID, (string) config('releases.app_store.certificate_id')) !== 1) {
            $missing[] = 'ASC_RELEASE_CERTIFICATE_ID';
        }
        try {
            self::testerUserIds();
        } catch (AppStoreNotConfigured) {
            $missing[] = 'releases.app_store.tester_user_ids';
        }

        return $missing;
    }

    // Det som JWT:n behöver. Kontrolleras före varje anrop.
    public static function assertKeyReady(): void
    {
        if (($missing = self::missingKey()) !== []) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: '.implode(', ', $missing).'.');
        }
    }

    public static function keyId(): string
    {
        return (string) config('releases.app_store.key_id');
    }

    public static function issuerId(): string
    {
        return (string) config('releases.app_store.issuer_id');
    }

    public static function certificateId(): string
    {
        $id = (string) config('releases.app_store.certificate_id');
        if (preg_match(self::KEY_ID, $id) !== 1) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: ASC_RELEASE_CERTIFICATE_ID.');
        }

        return $id;
    }

    public static function privateKeyFile(): string
    {
        return storage_path((string) config('releases.app_store.private_key_path'));
    }

    public static function betaGroup(): string
    {
        return (string) config('releases.app_store.beta_group');
    }

    /** @return list<string> användar-id i App Store Connect (R18) */
    public static function testerUserIds(): array
    {
        $ids = array_values((array) config('releases.app_store.tester_user_ids'));
        foreach ($ids as $id) {
            if (! is_string($id) || preg_match(self::ISSUER, $id) !== 1) {
                $ids = [];
                break;
            }
        }
        if ($ids === []) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: releases.app_store.tester_user_ids (user ids of App Store Connect users).');
        }

        return $ids;
    }

    /** @return list<string> */
    private static function missingKey(): array
    {
        $missing = [];
        if (preg_match(self::KEY_ID, self::keyId()) !== 1) {
            $missing[] = 'ASC_RELEASE_KEY_ID';
        }
        if (preg_match(self::ISSUER, self::issuerId()) !== 1) {
            $missing[] = 'ASC_RELEASE_ISSUER_ID';
        }
        $path = (string) config('releases.app_store.private_key_path');
        $file = self::privateKeyFile();
        // Nyckeln får inte vara läsbar för grupp eller andra (samma regel som GitHub-nyckeln).
        if ($path === '' || ! is_file($file) || ! is_readable($file) || (fileperms($file) & 0077) !== 0) {
            $missing[] = 'ASC_RELEASE_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)';
        }

        return $missing;
    }
}
