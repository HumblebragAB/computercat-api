<?php

namespace App\Provisioning;

final class ProvisioningConfig
{
    /** env-namn => config-nyckel. Allt här krävs innan create_game skapar något. */
    private const REQUIRED = [
        'GITHUB_APP_CLIENT_ID' => 'provisioning.github.client_id',
        'GITHUB_APP_INSTALLATION_ID' => 'provisioning.github.installation_id',
        'GITHUB_APP_PRIVATE_KEY_PATH' => 'provisioning.github.private_key_path',
        'GITHUB_OWNER' => 'provisioning.github.owner',
        'COOLIFY_URL' => 'provisioning.coolify.url',
        'COOLIFY_TOKEN' => 'provisioning.coolify.token',
        'COOLIFY_PROJECT_UUID' => 'provisioning.coolify.project_uuid',
        'COOLIFY_ENVIRONMENT_NAME' => 'provisioning.coolify.environment_name',
        'COOLIFY_SERVER_UUID' => 'provisioning.coolify.server_uuid',
        'COOLIFY_GITHUB_APP_UUID' => 'provisioning.coolify.github_app_uuid',
        'SITEOPS_URL' => 'provisioning.siteops.url',
        'SITEOPS_API_KEY' => 'provisioning.siteops.api_key',
        'DEMO_BASIC_AUTH_USER' => 'provisioning.demo.basic_auth_user',
        'DEMO_BASIC_AUTH_PASSWORD' => 'provisioning.demo.basic_auth_password',
        'SLACK_ALERT_WEBHOOK_URL' => 'provisioning.slack_webhook_url',
    ];

    public const KEY_FILE_PROBLEM = 'GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)';

    /** @return list<string> env-namnen som saknas; aldrig några värden. */
    public static function missing(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $env => $key) {
            $value = config($key);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $env;
            }
        }

        if (! in_array('GITHUB_APP_PRIVATE_KEY_PATH', $missing, true) && ! self::privateKeyUsable()) {
            $missing[] = self::KEY_FILE_PROBLEM;
        }

        // Token och API-nyckel skickas som headers: aldrig över http://.
        foreach (['COOLIFY_URL' => 'provisioning.coolify.url', 'SITEOPS_URL' => 'provisioning.siteops.url'] as $env => $key) {
            if (! in_array($env, $missing, true) && parse_url((string) config($key), PHP_URL_SCHEME) !== 'https') {
                $missing[] = "{$env} (must start with https://)";
            }
        }

        return $missing;
    }

    public static function privateKeyFile(): string
    {
        return storage_path((string) config('provisioning.github.private_key_path'));
    }

    public static function demoUrl(string $slug): string
    {
        return 'https://'.$slug.'.'.config('provisioning.demo.domain_suffix');
    }

    public static function coolifyAppName(string $slug): string
    {
        return 'spel-'.$slug;
    }

    // Hamnar i URL:er (/orgs/{owner}/repos, /repos/{owner}/...): bara GitHubs namnformat.
    public static function githubOwner(): string
    {
        $owner = (string) config('provisioning.github.owner');
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/', $owner) !== 1) {
            throw new \InvalidArgumentException('GITHUB_OWNER är inte ett giltigt organisationsnamn.');
        }

        return $owner;
    }

    public static function repoFullName(string $slug): string
    {
        return self::githubOwner().'/'.$slug;
    }

    // Nyckeln får inte vara läsbar för grupp eller andra.
    private static function privateKeyUsable(): bool
    {
        $file = self::privateKeyFile();

        return is_file($file) && is_readable($file) && (fileperms($file) & 0077) === 0;
    }
}
