<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\ProvisioningConfig;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class ProvisioningConfigTest extends TestCase
{
    public function test_nothing_missing_when_configured(): void
    {
        ProvisioningFakes::configure();

        $this->assertSame([], ProvisioningConfig::missing());
    }

    public function test_missing_settings_are_named_by_env_variable(): void
    {
        ProvisioningFakes::configure();
        config(['provisioning.coolify.token' => '', 'provisioning.slack_webhook_url' => null]);

        $missing = ProvisioningConfig::missing();

        $this->assertContains('COOLIFY_TOKEN', $missing);
        $this->assertContains('SLACK_ALERT_WEBHOOK_URL', $missing);
        $this->assertNotContains('SITEOPS_API_KEY', $missing);
    }

    public function test_private_key_must_exist_with_mode_600(): void
    {
        ProvisioningFakes::configure();
        chmod(ProvisioningConfig::privateKeyFile(), 0644);

        $this->assertContains('GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)', ProvisioningConfig::missing());

        chmod(ProvisioningConfig::privateKeyFile(), 0600);
        config(['provisioning.github.private_key_path' => 'framework/testing/finns-inte.pem']);

        $this->assertContains('GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)', ProvisioningConfig::missing());
    }

    public function test_service_urls_must_be_https(): void
    {
        config(['provisioning.coolify.url' => 'http://coolify.test', 'provisioning.siteops.url' => 'http://siteops.test']);

        $missing = ProvisioningConfig::missing();
        $this->assertContains('COOLIFY_URL (must start with https://)', $missing);
        $this->assertContains('SITEOPS_URL (must start with https://)', $missing);
    }

    public function test_derived_names(): void
    {
        ProvisioningFakes::configure();

        $this->assertSame('https://kattsvep.apps.humblebrag.se', ProvisioningConfig::demoUrl('kattsvep'));
        $this->assertSame('spel-kattsvep', ProvisioningConfig::coolifyAppName('kattsvep'));
        $this->assertSame('HumblebragAB', ProvisioningConfig::githubOwner());
        $this->assertSame('HumblebragAB/kattsvep', ProvisioningConfig::repoFullName('kattsvep'));
    }

    public function test_owner_must_be_a_github_name(): void
    {
        ProvisioningFakes::configure();
        config(['provisioning.github.owner' => 'HumblebragAB/../x']);

        $this->expectException(\InvalidArgumentException::class);
        ProvisioningConfig::githubOwner();
    }

    public function test_reserved_slugs_include_base_list_and_env_list(): void
    {
        $this->assertContains('www', config('provisioning.reserved_slugs'));
        $this->assertContains('spel', config('provisioning.reserved_slugs'));
        $this->assertNotContains('', config('provisioning.reserved_slugs'));
    }

    public function test_slack_webhook_must_be_https(): void
    {
        ProvisioningFakes::configure();
        config(['provisioning.slack_webhook_url' => 'http://hooks.slack.test/services/T000/B000/x']);

        $this->assertContains('SLACK_ALERT_WEBHOOK_URL (must start with https://)', ProvisioningConfig::missing());
    }

    // DNS-etiketter är skiftlägesokänsliga; slugregeln kräver gemener, så en
    // reserverad underdomän i env med versaler måste ändå spärra slugen.
    public function test_reserved_subdomains_from_env_are_trimmed_and_lowercased(): void
    {
        $before = [$_ENV['PROVISIONING_RESERVED_SUBDOMAINS'] ?? null, $_SERVER['PROVISIONING_RESERVED_SUBDOMAINS'] ?? null];
        $_ENV['PROVISIONING_RESERVED_SUBDOMAINS'] = $_SERVER['PROVISIONING_RESERVED_SUBDOMAINS'] = ' Brandlens ,FOOTR,, pockettrove ';

        try {
            $reserved = (require config_path('provisioning.php'))['reserved_slugs'];
        } finally {
            foreach ([0 => '_ENV', 1 => '_SERVER'] as $i => $global) {
                if ($before[$i] === null) {
                    unset($GLOBALS[$global]['PROVISIONING_RESERVED_SUBDOMAINS']);
                } else {
                    $GLOBALS[$global]['PROVISIONING_RESERVED_SUBDOMAINS'] = $before[$i];
                }
            }
        }

        foreach (['brandlens', 'footr', 'pockettrove', 'www', 'spel'] as $slug) {
            $this->assertContains($slug, $reserved);
        }
        $this->assertNotContains('Brandlens', $reserved);
        $this->assertNotContains('FOOTR', $reserved);
        $this->assertNotContains('', $reserved);
    }
}
