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
            self::REPO.'/computercat-release/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'failure', 'uuid-1')),
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
            F::githubRun(1, 'completed', 'success', 'other-uuid'),
            F::githubRun(2, 'queued', null, 'the-uuid'),
        ]])]);

        $this->assertSame(2, $this->client()->findWorkflowRun('computercat-release', 'release-ios.yml', 'the-uuid', now()->subMinute())['id']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'event=workflow_dispatch') && str_contains(urldecode($r->url()), 'created=>='));
    }

    public function test_cancel_accepts_409_for_a_finished_run(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/runs/4711/cancel' => Http::response(['message' => 'Cannot cancel a workflow run that is completed.'], 409)]);

        $this->client()->cancelWorkflowRun('computercat-release', 4711);
        Http::assertSentCount(2); // token + cancel
    }
}
