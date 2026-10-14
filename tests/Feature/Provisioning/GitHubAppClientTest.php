<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\Support\ProvisioningFakes;

class GitHubAppClientTest extends ProvisioningTestCase
{
    private const TOKEN_URL = 'api.github.com/app/installations/4242/access_tokens';

    private function client(): GitHubAppClient
    {
        return app(GitHubAppClient::class);
    }

    public function test_jwt_is_rs256_with_client_id_and_short_lifetime(): void
    {
        $now = new DateTimeImmutable('2026-10-13 12:00:00');

        $token = (new Parser(new JoseEncoder))->parse($this->client()->jwt($now));

        $this->assertSame('RS256', $token->headers()->get('alg'));
        $this->assertTrue((new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText(ProvisioningFakes::keyPair()['public']))));
        $this->assertTrue($token->hasBeenIssuedBy('Iv23testclientid'));
        $this->assertSame($now->getTimestamp() - 60, $token->claims()->get('iat')->getTimestamp());
        $this->assertSame($now->getTimestamp() + 540, $token->claims()->get('exp')->getTimestamp());
        $this->assertFalse($token->claims()->has('nbf'));
    }

    public function test_installation_token_is_requested_with_jwt_and_cached_encrypted(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201)]);

        $first = $this->client()->installationToken();
        $second = $this->client()->installationToken();

        $this->assertSame('ghs_testinstallationtoken000000000001', $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_starts_with($r->header('Authorization')[0], 'Bearer ey')
            && $r->header('X-GitHub-Api-Version')[0] === '2022-11-28'
            && $r->body() === '');
        $this->assertStringNotContainsString($first, (string) Cache::get('provisioning:github:installation-token'));
    }

    public function test_create_sends_exact_body_and_mints_new_token_afterwards(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/orgs/HumblebragAB/repos' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'run-1'), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'run-1')),
        ]);

        $repo = $this->client()->createRepository('kattsvep', 'Computercat-spel kattsvep (run run-1)');
        $this->client()->getRepository('kattsvep');

        $this->assertSame('HumblebragAB/kattsvep', $repo['full_name']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.github.com/orgs/HumblebragAB/repos' && $r->data() === [
            'name' => 'kattsvep',
            'description' => 'Computercat-spel kattsvep (run run-1)',
            // Standardvärdet är false (publikt repo); skickas alltid uttryckligen.
            'private' => true,
            'auto_init' => true,
        ]);
        // En token före skapandet, en ny efter.
        $this->assertCount(2, Http::recorded(fn ($r) => str_ends_with($r->url(), '/access_tokens')));
    }

    public function test_create_returns_null_when_the_name_already_exists(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/orgs/HumblebragAB/repos' => Http::response(ProvisioningFakes::githubNameTaken(), 422),
        ]);

        $this->assertNull($this->client()->createRepository('kattsvep', 'd'));
    }

    // Ett 422 av annat skäl (t.ex. "the endpoint has been spammed") får aldrig
    // tolkas som "namnet upptaget": då skulle slugen släppas av fel anledning.
    public function test_create_throws_on_other_422(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/orgs/HumblebragAB/repos' => Http::response(['message' => 'Validation failed, or the endpoint has been spammed.'], 422),
        ]);

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('GitHub POST /orgs/HumblebragAB/repos returned 422');
        $this->client()->createRepository('kattsvep', 'd');
    }

    // 403 (t.ex. saknad rättighet, spike S8) kastas och syns; inget nytt försök.
    public function test_create_throws_on_403(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/orgs/HumblebragAB/repos' => Http::response(['message' => 'Resource not accessible by integration'], 403),
        ]);

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('returned 403');
        $this->client()->createRepository('kattsvep', 'd');
    }

    public function test_get_repository_returns_null_on_404_and_throws_on_500(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/finnsinte' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/HumblebragAB/trasig' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->assertNull($this->client()->getRepository('finnsinte'));

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('GitHub GET /repos/HumblebragAB/trasig returned 500');
        $this->client()->getRepository('trasig');
    }

    public function test_get_and_put_file(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md' => Http::sequence()
                ->push(ProvisioningFakes::githubContent('# Hej å'), 200)
                ->push(['content' => ['sha' => 'ny']], 200),
        ]);

        $file = $this->client()->getFile('kattsvep', 'README.md');
        $this->client()->putFile('kattsvep', 'README.md', 'Ny text', $file['sha'], 'Meddelande');

        $this->assertSame(['sha' => 'readme-sha-1', 'text' => '# Hej å'], $file);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['message' => 'Meddelande', 'content' => base64_encode('Ny text'), 'branch' => 'main', 'sha' => 'readme-sha-1']);
    }

    // En ny fil (CLAUDE.md) skapas utan sha (spike S11).
    public function test_put_new_file_omits_sha(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep/contents/CLAUDE.md' => Http::response(['content' => ['sha' => 'ny']], 201),
        ]);

        $this->client()->putFile('kattsvep', 'CLAUDE.md', '# CLAUDE.md', null, 'Meddelande');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['message' => 'Meddelande', 'content' => base64_encode('# CLAUDE.md'), 'branch' => 'main']);
    }

    public function test_get_file_returns_null_on_404(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertNull($this->client()->getFile('kattsvep', 'README.md'));
    }

    public function test_archive_patches_archived_true(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'r', archived: true)),
        ]);

        $this->assertTrue($this->client()->archiveRepository('kattsvep')['archived']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['archived' => true]);
    }

    public function test_invalid_names_are_refused_before_any_request(): void
    {
        Http::fake();

        foreach (['../x', 'HumblebragAB/x', 'a b', 'Kattsvep'] as $name) {
            try {
                $this->client()->getRepository($name);
                $this->fail("Ogiltigt namn släpptes igenom: {$name}");
            } catch (\InvalidArgumentException) {
                // väntat
            }
        }

        Http::assertNothingSent();
    }

    public function test_client_has_no_delete_capability(): void
    {
        $this->assertNotContains('DELETE', GitHubAppClient::ALLOWED_METHODS);
        foreach ((new \ReflectionClass(GitHubAppClient::class))->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression('/delete|remove|destroy/i', $method->getName());
        }
    }
}
