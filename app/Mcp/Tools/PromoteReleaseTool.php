<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Releases\ReleaseCatalog;
use App\Releases\ReleaseNotifier;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Releases\ReleaseStatus;
use App\Support\SlackAlert;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Etapp 1a bygger inget (R1): verktyget skapar släppraden och svarar med var den
// står och vad som händer härnäst. Slack skickas efter att transaktionen är klar.
class PromoteReleaseTool extends GameTool
{
    protected string $name = 'promote_release';

    protected string $description = 'Promote an exact commit to the next release stage. testflight (app_store) builds the iOS app: raise "version" in package.json (MAJOR.MINOR.PATCH, higher than the latest TestFlight release and not used before), push to main, wait for the green Demo build, then call this; Computercat tags v<version>, picks the build number, builds in GitHub Actions and puts the build in the TestFlight group "ComputerCat intern". play_internal is registered but not built yet. Production stages need an explicit commit_sha that succeeded in the test stage, and the first production release per game and target must be approved by Erik in Filament; production stages that are not built yet are refused. Test stages default to the latest commit with a successful Demo build. Only the game owner. Check get_release_status first; follow it after promoting.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'target' => $schema->string()->enum(ReleaseCatalog::TARGETS)->description('Production target: web, app_store or play_store.')->required(),
            'stage' => $schema->string()->enum(ReleaseCatalog::PROMOTABLE_STAGES)->description('Stage to promote to. Must belong to the target (web: web_production; app_store: testflight, app_store; play_store: play_internal, play_production).')->required(),
            'commit_sha' => $schema->string()->description('Full 40-character lower-case commit SHA. Optional for test stages, required for production stages.'),
        ];
    }

    // Slack-anropet får inte ske inne i en databastransaktion; ReleaseService har sin egen.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        // Tom sträng behandlas som utelämnad (som i övriga verktyg).
        if (array_key_exists('commit_sha', $args) && is_string($args['commit_sha']) && trim($args['commit_sha']) === '') {
            $args['commit_sha'] = null;
        }

        $validator = Validator::make($args, [
            'target' => ['bail', 'required', 'string', Rule::in(ReleaseCatalog::TARGETS)],
            'stage' => ['bail', 'required', 'string', Rule::in(ReleaseCatalog::PROMOTABLE_STAGES)],
            'commit_sha' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $data = $validator->validated();

        try {
            $release = app(ReleaseService::class)->promote($game, $user, $data['target'], $data['stage'], $data['commit_sha'] ?? null);
        } catch (ReleaseRefused $e) {
            return Response::error($e->getMessage());
        }

        $described = ReleaseStatus::describe($release);

        // Notifieraren ska inte kasta, men en cache- eller nätverksstörning får aldrig
        // fälla en redan skapad release: fånga, logga och rapportera som failed.
        try {
            $notification = app(ReleaseNotifier::class)->afterPromote($release);
        } catch (\Throwable $e) {
            report($e);
            $notification = SlackAlert::FAILED;
        }
        $notified = $notification === null || in_array($notification, [SlackAlert::SENT, SlackAlert::DUPLICATE], true);

        return $this->json([
            'release' => $release->uuid,
            'target' => $release->target,
            'stage' => $release->stage,
            'commit_sha' => $release->commit_sha,
            'marketing_version' => $release->marketing_version,
            'build_number' => $release->build_number,
            'status' => $release->status,
            'state' => $described['state'],
            'next' => $described['next'],
            // Visar om Erik fick Slack-beskedet (sent, duplicate, failed, not_configured); null för teststeg.
            'notification' => $notification,
        ] + ($notified ? [] : [
            'warning' => 'The release is saved, but Erik was NOT notified in Slack ('.$notification.'). Tell Erik directly that it is waiting.',
        ]));
    }
}
