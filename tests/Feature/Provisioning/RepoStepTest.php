<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Provisioning\ReadmeIdea;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\RepoStep;
use App\Services\BuildGuide;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class RepoStepTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const TOKEN = 'api.github.com/app/installations/4242/access_tokens';

    private const REPO = 'api.github.com/repos/HumblebragAB/kattsvep';

    private const CREATE = 'api.github.com/orgs/HumblebragAB/repos';

    private const README = 'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md';

    private const CLAUDE = 'api.github.com/repos/HumblebragAB/kattsvep/contents/CLAUDE.md';

    private function run_(): ProvisioningRun
    {
        return $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'idea' => 'Minröj där katter letar bajs.']);
    }

    private function wantedReadme(): string
    {
        return ReadmeIdea::insert(BuildGuide::readme('v1', 'Kattsvep'), 'Minröj där katter letar bajs.');
    }

    public function test_creates_repo_waits_for_readme_then_writes_readme_and_claude_md(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(['message' => 'Not Found'], 404),
            self::CREATE => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid), 201),
            self::README => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(ProvisioningFakes::githubContent(ProvisioningFakes::autoInitReadme('kattsvep')))
                ->push(['content' => ['sha' => 'ny']]),
            self::CLAUDE => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(['content' => ['sha' => 'claude']], 201),
        ]);

        $first = $this->runStep(RepoStep::class, $run, 'repo');
        $second = $this->runStep(RepoStep::class, $run, 'repo');

        $this->assertSame(StepResult::WAIT, $first->outcome);
        $this->assertSame(10, $first->seconds);
        $this->assertSame(StepResult::DONE, $second->outcome);
        $this->assertSame('HumblebragAB/kattsvep', $run->fresh()->github_repo);
        $this->assertSame('v1', $run->steps()->where('step', 'repo')->first()->detail['build_guide']);
        Http::assertSent(fn ($r) => $r->url() === 'https://'.self::CREATE && $r['private'] === true && $r['auto_init'] === true && $r['description'] === "Computercat-spel kattsvep (run {$run->uuid})");
        // README: hela filen ersätts av mallen med namn och idé, med auto_init-filens sha.
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/README.md') && base64_decode($r['content']) === $this->wantedReadme() && $r['sha'] === 'readme-sha-1');
        // CLAUDE.md: ny fil, exakt byggguidens, utan sha.
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/CLAUDE.md') && base64_decode($r['content']) === BuildGuide::claudeMd('v1') && ! isset($r['sha']));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === 'https://'.self::CREATE));
    }

    public function test_own_repository_is_adopted_on_retry_and_correct_files_are_not_rewritten(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(ProvisioningFakes::githubContent($this->wantedReadme())),
            self::CLAUDE => Http::response(ProvisioningFakes::githubContent(BuildGuide::claudeMd('v1'), 'claude-sha')),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://'.self::CREATE || $r->method() === 'PUT');
    }

    public function test_changed_claude_md_is_overwritten_with_its_sha(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(ProvisioningFakes::githubContent($this->wantedReadme())),
            self::CLAUDE => Http::sequence()
                ->push(ProvisioningFakes::githubContent("# Gammal\n", 'claude-sha'))
                ->push(['content' => ['sha' => 'ny']]),
        ]);

        $this->runStep(RepoStep::class, $run, 'repo');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/CLAUDE.md') && $r['sha'] === 'claude-sha');
    }

    public function test_foreign_repository_releases_the_slug(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'annan-run', description: 'Mattias gamla repo')),
        ]);

        $result = $this->runStep(RepoStep::class, $run, 'repo');

        $this->assertSame(StepResult::RELEASE, $result->outcome);
        $this->assertStringContainsString('Slug is taken on GitHub', $result->reason);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://'.self::CREATE || $r->method() === 'PUT');
    }

    // Ett publikt repo adopteras aldrig, även med vårt run-uuid i beskrivningen.
    public function test_public_repo_with_our_uuid_is_not_adopted(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid, private: false)),
        ]);

        $this->assertSame(StepResult::RELEASE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    // Spec: 422 "name already exists" utan match släpper slugen. Appen ser inte
    // repon den inte skapat ("Only select repositories"), så det vanliga fallet
    // är 422 följt av 404; ett synligt främmande repo släpper också slugen.
    public function test_name_taken_releases_whether_or_not_the_repo_is_visible(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(ProvisioningFakes::githubRepo('kattsvep', 'annan-run'))
                ->push(['message' => 'Not Found'], 404)
                ->push(['message' => 'Not Found'], 404),
            self::CREATE => Http::response(ProvisioningFakes::githubNameTaken(), 422),
        ]);

        $this->assertSame(StepResult::RELEASE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);

        $invisible = $this->runStep(RepoStep::class, $run, 'repo');
        $this->assertSame(StepResult::RELEASE, $invisible->outcome);
        $this->assertStringContainsString('Slug is taken on GitHub', $invisible->reason);
    }

    // Ett 422 av annat skäl släpper aldrig slugen: steget fallerar synligt.
    public function test_other_422_fails_without_releasing(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(['message' => 'Not Found'], 404),
            self::CREATE => Http::response(['message' => 'Validation failed, or the endpoint has been spammed.'], 422),
        ]);

        $this->expectException(\App\Provisioning\Http\ExternalApiException::class);
        $this->runStep(RepoStep::class, $run, 'repo');
    }

    // En omkörning skriver samma guideversion som första försöket, även om en nyare kommit.
    public function test_retry_keeps_the_build_guide_version_of_the_first_attempt(): void
    {
        $run = $this->run_();
        $this->stepRow($run, 'repo')->update(['detail' => ['repo_ok' => true, 'readme_wait_started_at' => now()->toIso8601String(), 'build_guide' => 'v1']]);
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::README => Http::response(ProvisioningFakes::githubContent($this->wantedReadme())),
            self::CLAUDE => Http::response(ProvisioningFakes::githubContent(BuildGuide::claudeMd('v1'))),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT' || str_ends_with($r->url(), '/repos/HumblebragAB/kattsvep'));
    }

    public function test_readme_that_never_appears_fails_after_timeout(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(StepResult::WAIT, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        $this->travel(4)->minutes();

        $this->expectException(\App\Provisioning\StepFailed::class);
        $this->runStep(RepoStep::class, $run, 'repo');
    }
}
