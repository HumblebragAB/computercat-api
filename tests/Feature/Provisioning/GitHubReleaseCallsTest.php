<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class GitHubReleaseCallsTest extends TestCase
{
    private const SHA = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const UUID = '0b6f4f0e-0d1c-4f5e-9a55-3c0f1f4b2a11';

    private const OTHER = '7a1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d';

    private const REPO = F::GITHUB.'/repos/HumblebragAB';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
    }

    private function fake(array $routes): void
    {
        Http::fake([F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201)] + $routes);
    }

    private function client(): GitHubAppClient
    {
        return app(GitHubAppClient::class);
    }

    public function test_get_file_at_a_commit_sends_ref_and_refuses_anything_but_a_full_sha(): void
    {
        $this->fake([self::REPO.'/kattsvep/contents/package.json?ref='.self::SHA => Http::response(F::githubContent('{"version":"1.2.0"}'))]);

        $this->assertSame('{"version":"1.2.0"}', $this->client()->getFile('kattsvep', 'package.json', self::SHA)['text']);

        $this->expectException(\InvalidArgumentException::class);
        $this->client()->getFile('kattsvep', 'package.json', 'main');
    }

    public function test_get_tag_returns_sha_and_type_and_null_when_missing(): void
    {
        $this->fake([
            self::REPO.'/kattsvep/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', self::SHA)),
            self::REPO.'/kattsvep/git/ref/tags/v1.3.0' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(['sha' => self::SHA, 'type' => 'commit'], $this->client()->getTag('kattsvep', 'v1.2.0'));
        $this->assertNull($this->client()->getTag('kattsvep', 'v1.3.0'));
    }

    public function test_create_tag_posts_a_lightweight_ref(): void
    {
        $this->fake([self::REPO.'/kattsvep/git/refs' => Http::response(F::githubTagRef('v1.2.0', self::SHA), 201)]);

        $this->client()->createTag('kattsvep', 'v1.2.0', self::SHA);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['ref' => 'refs/tags/v1.2.0', 'sha' => self::SHA]);
    }

    public function test_tag_must_be_v_major_minor_patch(): void
    {
        $this->fake([]);
        foreach (['1.2.0', 'v1.2', 'v01.2.0', 'v1.2.0-rc1', 'v1.2.0/../x', "v1.2.0\n"] as $bad) {
            try {
                $this->client()->getTag('kattsvep', $bad);
                $this->fail("{$bad} släpptes igenom.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dispatch_sends_ref_string_inputs_and_return_run_details_and_reads_the_run_id(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/dispatches' => Http::response(F::githubDispatch(4711))]);

        $run = $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['game' => 'kattsvep', 'build_number' => '7']);

        $this->assertSame(['run_id' => 4711, 'html_url' => 'https://github.com/HumblebragAB/computercat-release/actions/runs/4711'], $run);
        Http::assertSent(fn (Request $r) => $r->data() === ['ref' => 'main', 'inputs' => ['game' => 'kattsvep', 'build_number' => '7'], 'return_run_details' => true]);
    }

    public function test_dispatch_without_a_run_id_fails_loud(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/dispatches' => Http::response('', 204)]);

        $this->expectException(ExternalApiException::class);
        $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['game' => 'kattsvep']);
    }

    public function test_non_string_inputs_are_refused_before_any_request(): void
    {
        $this->fake([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['build_number' => 7]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_workflow_run_and_the_failed_step(): void
    {
        $this->fake([
            self::REPO.'/computercat-release/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'failure', self::UUID)),
            self::REPO.'/computercat-release/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs(['build', 'Kontrollera version och bundle-id'])),
        ]);

        $run = $this->client()->workflowRun('computercat-release', 4711);
        $this->assertSame(['completed', 'failure', str_repeat('9', 40)], [$run['status'], $run['conclusion'], $run['head_sha']]);
        $failed = array_values(array_filter($this->client()->workflowRunJobs('computercat-release', 4711), fn (array $j) => $j['conclusion'] === 'failure'));
        $this->assertSame([['name' => 'build', 'conclusion' => 'failure', 'failed_step' => 'Kontrollera version och bundle-id']], $failed);
    }

    public function test_find_run_by_release_uuid_in_run_name(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 2, 'workflow_runs' => [
            F::githubRun(1, 'completed', 'success', self::OTHER),
            F::githubRun(2, 'queued', null, self::UUID),
        ]])]);

        $this->assertSame(2, $this->client()->findWorkflowRun('computercat-release', 'release-ios.yml', self::UUID, now()->subMinute())['id']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'event=workflow_dispatch') && str_contains(urldecode($r->url()), 'created=>='));
    }

    public function test_cancel_accepts_409_for_a_finished_run(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/runs/4711/cancel' => Http::response(['message' => 'Cannot cancel a workflow run that is completed.'], 409)]);

        $this->client()->cancelWorkflowRun('computercat-release', 4711);
        Http::assertSentCount(2); // token + cancel
    }

    public function test_dispatch_and_cancel_only_reach_the_release_repo_and_its_workflow(): void
    {
        $this->fake([]);
        $calls = [
            fn () => $this->client()->dispatchWorkflow('kattsvep', 'release-ios.yml', 'main', ['game' => 'kattsvep']),
            fn () => $this->client()->dispatchWorkflow('computercat-release', 'deploy.yml', 'main', ['game' => 'kattsvep']),
            fn () => $this->client()->dispatchWorkflow('computercat-ankare', 'release-ios.yml', 'main', []),
            fn () => $this->client()->cancelWorkflowRun('kattsvep', 4711),
            fn () => $this->client()->workflowRun('kattsvep', 4711),
            fn () => $this->client()->workflowRunJobs('kattsvep', 4711),
            fn () => $this->client()->findWorkflowRun('kattsvep', 'release-ios.yml', self::UUID, now()),
            fn () => $this->client()->findWorkflowRun('computercat-release', 'deploy.yml', self::UUID, now()),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Anrop {$i} släpptes igenom.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_reserved_slugs_are_refused_for_tags_and_files(): void
    {
        $this->fake([]);
        $calls = [
            fn () => $this->client()->getTag('computercat-release', 'v1.2.0'),
            fn () => $this->client()->createTag('computercat-release', 'v1.2.0', self::SHA),
            fn () => $this->client()->getFile('computercat-release', 'package.json', self::SHA),
            fn () => $this->client()->putFile('computercat-release', 'README.md', 'x', null, 'm'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Reserverat reponamn släpptes igenom.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_find_run_needle_must_be_a_uuid(): void
    {
        $this->fake([]);
        foreach (['', 'the-uuid', self::UUID.' x', "x\n"] as $bad) {
            try {
                $this->client()->findWorkflowRun('computercat-release', 'release-ios.yml', $bad, now());
                $this->fail('Ogiltigt uppslag släpptes igenom.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dispatch_limits_inputs_and_never_echoes_values(): void
    {
        $this->fake([]);
        $secret = 'hemligt-varde-'.str_repeat('x', 250);
        $many = [];
        for ($i = 0; $i < 26; $i++) {
            $many["k{$i}"] = 'v';
        }
        foreach ([['game' => $secret], $many, ['Bad Key' => 'v']] as $inputs) {
            try {
                $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', $inputs);
                $this->fail('Ogiltig indata släpptes igenom.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString('hemligt-varde', $e->getMessage());
                $this->assertStringNotContainsString('Bad Key', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_get_tag_exposes_an_annotated_tag_as_type_tag(): void
    {
        $this->fake([self::REPO.'/kattsvep/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', self::SHA, 'tag'))]);

        $this->assertSame('tag', $this->client()->getTag('kattsvep', 'v1.2.0')['type']);
    }
}
