<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\ReadmeIdea;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Services\BuildGuide;
use Illuminate\Support\Carbon;

// Ett tomt privat repo skapas (auto_init) och får README (byggguidens mall med
// namn och idé) och CLAUDE.md (byggguidens). Grundkoden skriver spelets Claude
// själv med get_build_guide. Ett repo med samma namn adopteras bara om det
// bevisligen skapats av just den här runnen (run-uuid i beskrivningen, privat);
// annars släpps slugen.
final class RepoStep implements Step
{
    public function __construct(private GitHubAppClient $github) {}

    // Tre körningar, så att varje jobb håller sig inom ett fåtal anrop (jobbets
    // timeout är 60 s, varje GitHub-anrop får vänta 20 s):
    //   1. repot skapas/adopteras, 2. README skrivs, 3. CLAUDE.md skrivs.
    // Varje fas sparar sitt resultat i detail och återköar jobbet direkt.
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $detail = $step->detail ?? [];

        if (! ($detail['repo_ok'] ?? false)) {
            $release = $this->ensureRepository($run);
            if ($release !== null) {
                return $release;
            }
            $run->update(['github_repo' => ProvisioningConfig::repoFullName($run->slug)]);

            // Samma guideversion vid omkörning, även om en nyare publiceras under tiden.
            return StepResult::wait(0, [
                'repo_ok' => true,
                'repo_name' => $run->slug,
                'readme_wait_started_at' => now()->toIso8601String(),
                'build_guide' => BuildGuide::LATEST,
            ]);
        }

        $version = $detail['build_guide'] ?? BuildGuide::LATEST;

        if (! ($detail['readme_ok'] ?? false)) {
            return $this->writeReadme($run, $detail, $version);
        }

        $this->writeClaudeMd($run, $version);

        return StepResult::done(['readme' => 'written', 'claude_md' => 'written']);
    }

    private function writeReadme(ProvisioningRun $run, array $detail, string $version): StepResult
    {
        // auto_init skapar README i första commiten; den kan saknas en kort stund.
        $readme = $this->github->getFile($run->slug, 'README.md');
        if ($readme === null) {
            // Saknas tidsstämpeln (nollad av retry) startar väntefönstret om.
            $started = $detail['readme_wait_started_at'] ?? null;
            if ($started === null) {
                return StepResult::wait(10, ['readme_wait_started_at' => now()->toIso8601String()]);
            }
            $minutes = (int) config('provisioning.repo_ready_timeout_minutes');
            if (Carbon::parse($started)->addMinutes($minutes)->isPast()) {
                throw new StepFailed("README.md did not appear in the new repository within {$minutes} minutes.");
            }

            return StepResult::wait(10);
        }

        // Hela README ersätts; ReadmeIdea fallerar (och inget skrivs) om mallens markörer är fel.
        $wanted = ReadmeIdea::insert(BuildGuide::readme($version, (string) $run->name), (string) $run->idea);
        if ($readme['text'] !== $wanted) {
            $this->github->putFile($run->slug, 'README.md', $wanted, $readme['sha'], "README från byggguide {$version} (run {$run->uuid})");
        }

        return StepResult::wait(0, ['readme_ok' => true]);
    }

    private function writeClaudeMd(ProvisioningRun $run, string $version): void
    {
        $claude = BuildGuide::claudeMd($version);
        $existing = $this->github->getFile($run->slug, 'CLAUDE.md');
        if ($existing === null) {
            $this->github->putFile($run->slug, 'CLAUDE.md', $claude, null, "CLAUDE.md från byggguide {$version} (run {$run->uuid})");
        } elseif ($existing['text'] !== $claude) {
            $this->github->putFile($run->slug, 'CLAUDE.md', $claude, $existing['sha'], "CLAUDE.md från byggguide {$version} (run {$run->uuid})");
        }
    }

    private function ensureRepository(ProvisioningRun $run): ?StepResult
    {
        $existing = $this->github->getRepository($run->slug);

        if ($existing === null) {
            $created = $this->github->createRepository($run->slug, "Computercat-spel {$run->slug} (run {$run->uuid})");
            if ($created !== null) {
                return null;
            }

            // 422 "name already exists" (andra 422 kastar i klienten). Appen är
            // installerad på "Only select repositories" och ser bara repon den
            // själv skapat, så ett främmande repo ger 404 här.
            $existing = $this->github->getRepository($run->slug);
            if ($existing === null) {
                return StepResult::releaseSlug('Slug is taken on GitHub: '.ProvisioningConfig::repoFullName($run->slug).' already exists (not visible to Computercat). Choose another slug.');
            }
        }

        if ($this->belongsToRun($existing, $run)) {
            return null;
        }

        return StepResult::releaseSlug('Slug is taken on GitHub: '.ProvisioningConfig::repoFullName($run->slug).' exists and was not created by this run. Choose another slug.');
    }

    private function belongsToRun(array $repo, ProvisioningRun $run): bool
    {
        return is_string($repo['description'] ?? null)
            && str_contains($repo['description'], $run->uuid)
            && ($repo['private'] ?? null) === true;
    }
}
