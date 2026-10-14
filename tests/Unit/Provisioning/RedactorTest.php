<?php

namespace Tests\Unit\Provisioning;

use App\Provisioning\Http\Redactor;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    public function test_sensitive_keys_are_redacted_recursively(): void
    {
        $out = Redactor::redact([
            'name' => 'spel-kattsvep',
            'http_basic_auth_password' => 'x1',
            'environment' => ['basic_auth_password' => 'x2', 'url' => 'https://k.apps.humblebrag.se'],
            'Authorization' => 'Bearer x3',
            'private_key' => 'x4',
            'token' => 'x5',
            'value' => 'x6',
            'logs' => 'x7',
            'X-Api-Key' => 'x8',
            'list' => [['value' => 'x9', 'key' => 'NEXT_PUBLIC_COMPUTERCAT_GAME']],
        ]);

        $flat = json_encode($out);
        foreach (['x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7', 'x8', 'x9'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}", $flat, $secret);
        }
        $this->assertSame('spel-kattsvep', $out['name']);
        $this->assertSame('https://k.apps.humblebrag.se', $out['environment']['url']);
        $this->assertSame('NEXT_PUBLIC_COMPUTERCAT_GAME', $out['list'][0]['key']);
    }

    public function test_known_secrets_and_github_tokens_are_scrubbed_from_text(): void
    {
        ProvisioningFakes::configure();
        Redactor::registerSecret('ghs_issuedtoken1234567890abcdefXYZ');

        $text = Redactor::scrubSecrets('a '.ProvisioningFakes::COOLIFY_TOKEN.' b '.ProvisioningFakes::SITEOPS_KEY.' c '.ProvisioningFakes::SLACK.' d ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ12 e ghs_issuedtoken1234567890abcdefXYZ');

        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $text);
        $this->assertStringNotContainsString(ProvisioningFakes::SITEOPS_KEY, $text);
        $this->assertStringNotContainsString('hemligsokvag123', $text);
        $this->assertStringNotContainsString('ghp_ABCDEFGHIJ', $text);
        $this->assertStringNotContainsString('ghs_issuedtoken', $text);
    }

    // Byggs vid körning (inga nyckel- eller JWT-literaler i repot, gitleaks).
    public function test_app_jwt_and_private_key_are_scrubbed_from_text(): void
    {
        ProvisioningFakes::configure();
        $pem = ProvisioningFakes::keyPair()['private'];
        // Samma form som GitHubAppClient::jwt() (Task 9), byggd direkt med lcobucci.
        $jwt = \Lcobucci\JWT\Token\Builder::new(new \Lcobucci\JWT\Encoding\JoseEncoder, \Lcobucci\JWT\Encoding\ChainedFormatter::withUnixTimestampDates())
            ->issuedBy('Iv23testclientid')
            ->issuedAt(new \DateTimeImmutable('-60 seconds'))
            ->expiresAt(new \DateTimeImmutable('+9 minutes'))
            ->getToken(new \Lcobucci\JWT\Signer\Rsa\Sha256, \Lcobucci\JWT\Signer\Key\InMemory::plainText($pem))
            ->toString();

        $text = Redactor::scrubSecrets("Authorization: Bearer {$jwt}\n{$pem}\nslut");

        $this->assertStringNotContainsString($jwt, $text);
        $this->assertStringNotContainsString('PRIVATE KEY', $text);
        $this->assertStringNotContainsString(substr(explode("\n", $pem)[1], 0, 40), $text);
        $this->assertStringEndsWith('slut', $text);
    }

    public function test_body_redacts_json_and_limits_size(): void
    {
        $body = Redactor::body(json_encode(['message' => 'fel', 'logs' => str_repeat('L', 10000)]));
        $this->assertStringNotContainsString('LLLL', $body);
        $this->assertStringContainsString('fel', $body);

        $long = Redactor::body(str_repeat('å', 5000));
        $this->assertLessThanOrEqual(Redactor::MAX_BYTES, strlen($long));
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));
        $this->assertStringEndsWith('…', $long);
    }
}
