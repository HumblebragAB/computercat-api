<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class TestFlightPromoteTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    private Game $game;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        F::configure();
        $this->iosGuides('v3');
        $this->startHttpTransactionGuard();
        $this->game = $this->testFlightGame();
        $this->owner = $this->ownerOf($this->game);
        $this->demoBuild($this->game, $this->sha('a'));
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    /** Spelrepot vid commiten. $tag = ['sha' => …, 'type' => …] om taggen v<version> redan finns. */
    private function repo(mixed $version = '1.2.0', string $guide = 'v3', ?array $tag = null, int $packageStatus = 200): void
    {
        $base = F::GITHUB.'/repos/HumblebragAB/kattsvep';
        Http::fake([
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            $base.'/contents/package.json?ref=*' => $packageStatus === 200
                ? Http::response(F::githubContent(json_encode(['name' => 'kattsvep', 'version' => $version])))
                : Http::response(['message' => 'Server Error'], $packageStatus),
            $base.'/contents/CLAUDE.md?ref=*' => Http::response(F::githubContent("# CLAUDE.md\n\nByggguide: **{$guide}**. Grundkoden kommer från byggguiden.\n")),
            $base.'/git/ref/tags/*' => $tag === null
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response(F::githubTagRef("v{$version}", $tag['sha'], $tag['type'] ?? 'commit')),
        ]);
    }

    private function promote(?string $commit = null): Release
    {
        return app(ReleaseService::class)->promote($this->game->fresh(), $this->owner, 'app_store', 'testflight', $commit);
    }

    private function refused(string ...$needles): void
    {
        try {
            $this->promote();
            $this->fail('Släppet nekades inte.');
        } catch (ReleaseRefused $e) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->assertSame(0, Release::where('stage', 'testflight')->where('status', Release::REQUESTED)->count());
    }

    private function released(string $version, string $status = Release::SUCCEEDED, int $build = 1, ?string $commit = null): Release
    {
        return $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => $version, 'build_number' => $build, 'build_guide' => 'v3', 'commit_sha' => $commit ?? $this->sha('c')]);
    }

    public function test_promote_reads_version_and_guide_at_the_commit_and_takes_the_next_build_number(): void
    {
        $this->game->forceFill(['ios_build_number' => 4])->save();
        $this->repo('1.2.0');

        $release = $this->promote();

        $this->assertSame([Release::REQUESTED, $this->sha('a'), '1.2.0', 5, 'v3'], [$release->status, $release->commit_sha, $release->marketing_version, $release->build_number, $release->build_guide]);
        $this->assertSame(5, $this->game->fresh()->ios_build_number);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/package.json?ref='.$this->sha('a')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/ref/tags/v1.2.0'));
    }

    public function test_version_must_be_higher_than_the_latest_succeeded_release(): void // R3
    {
        $this->released('1.2.0');
        $this->repo('1.2.0');

        $this->refused('1.2.0 is already released to TestFlight', 'for example 1.2.1');
    }

    public function test_lower_version_is_refused(): void
    {
        $this->released('2.0.0');
        $this->repo('1.9.9');

        $this->refused('above 2.0.0');
    }

    public function test_versions_compare_numerically(): void
    {
        $this->released('1.9.0');
        $this->repo('1.10.0');

        $this->assertSame('1.10.0', $this->promote()->marketing_version);
    }

    public function test_failed_release_does_not_block_a_retry_of_the_same_commit(): void
    {
        $this->released('1.2.0', Release::FAILED, 3, $this->sha('a'));
        $this->game->forceFill(['ios_build_number' => 3])->save();
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a')]);

        $release = $this->promote();

        $this->assertSame(['1.2.0', 4], [$release->marketing_version, $release->build_number]);
    }

    public function test_tag_on_another_commit_is_refused_with_bump_advice(): void // R3
    {
        $this->repo('1.2.0', tag: ['sha' => $this->sha('f')]);

        $this->refused('Tag v1.2.0 already exists on another commit', 'for example 1.2.1');
    }

    public function test_annotated_tag_is_treated_as_another_commit(): void
    {
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a'), 'type' => 'tag']);

        $this->refused('Tag v1.2.0 already exists');
    }

    public function test_invalid_version_is_refused_with_the_format(): void
    {
        $this->repo('1.2');

        $this->refused('"1.2"', 'MAJOR.MINOR.PATCH');
    }

    public function test_a_huge_version_value_is_cut_in_the_answer(): void
    {
        $this->repo(str_repeat('9', 5000));

        try {
            $this->promote();
            $this->fail('Släppet nekades inte.');
        } catch (ReleaseRefused $e) {
            $this->assertLessThan(500, strlen($e->getMessage()));
        }
    }

    public function test_build_guide_without_ios_is_refused(): void
    {
        $this->repo('1.2.0', guide: 'v2');

        $this->refused('build guide v2', 'cannot be built for iOS');
        $this->refused('build guide v2', 'build guide v3 or later (get_build_guide)');
    }

    public function test_missing_app_store_setup_is_refused_before_any_github_call(): void
    {
        $this->game->forceFill(['app_store_ids' => ['bundle_resource_id' => 'WN54V4BM5D']])->save();
        Http::fake();

        $this->refused('App Store setup is not complete', 'missing profile_id');
        Http::assertNothingSent();
    }

    public function test_gate_refusal_comes_before_github(): void
    {
        $this->game->demoBuilds()->delete();
        Http::fake();

        $this->refused('No successful Demo build');
        Http::assertNothingSent();
    }

    public function test_github_error_becomes_a_clear_refusal(): void
    {
        $this->repo('1.2.0', packageStatus: 502);

        $this->refused('Could not read the game repository', 'returned 502');
    }

    public function test_build_numbers_never_repeat(): void // Review Focus 2
    {
        $this->game->forceFill(['ios_build_number' => 7])->save();
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a')]);

        $first = $this->promote();
        $first->update(['status' => Release::FAILED]);
        $second = $this->promote();

        $this->assertSame([8, 9], [$first->build_number, $second->build_number]);
        $this->assertSame(9, $this->game->fresh()->ios_build_number);
    }

    public function test_a_pending_testflight_release_is_refused_before_github(): void // spärr 4
    {
        $this->released('1.1.0', Release::BUILDING, 2);
        Http::fake();

        $this->refused('already pending');
        Http::assertNothingSent();
    }

    public function test_a_counter_behind_the_used_build_numbers_skips_past_them_and_logs(): void // R5
    {
        $this->released('1.1.0', Release::FAILED, 6);
        $this->game->forceFill(['ios_build_number' => 2])->save();
        $this->repo('1.2.0');
        Log::spy();

        $this->assertSame(7, $this->promote()->build_number);
        $this->assertSame(7, $this->game->fresh()->ios_build_number);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'ios_build_number'))->once();
    }
}
