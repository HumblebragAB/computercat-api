<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AchievementDefinitionResource;
use App\Http\Resources\Api\V1\UserAchievementResource;
use App\Models\AchievementDefinition;
use App\Models\Game;
use App\Models\GameSave;
use App\Models\UserAchievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Game $game): JsonResponse
    {
        $definitions = AchievementDefinition::where('game_id', $game->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => AchievementDefinitionResource::collection($definitions),
        ]);
    }

    public function me(Request $request, Game $game): JsonResponse
    {
        $achievements = UserAchievement::where('user_id', $request->user()->id)
            ->whereHas('achievementDefinition', fn ($q) => $q->where('game_id', $game->id))
            ->with('achievementDefinition')
            ->orderBy('unlocked_at')
            ->get();

        return response()->json([
            'data' => UserAchievementResource::collection($achievements),
        ]);
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'slugs' => 'required|array|min:1|max:10',
            'slugs.*' => 'required|string|max:100',
        ]);
        $requested = array_values(array_unique($validated['slugs']));

        // Rimlighetskontroll: bara mot trösklar i spelets settings, och bara när molnsparningen
        // har en lista med resultat. Allt annat (ingen sparning, annan form) ger ingen kontroll.
        $save = GameSave::where('user_id', $request->user()->id)
            ->where('game_id', $game->id)
            ->where('save_key', 'main')
            ->first();
        $results = $save?->data['progress']['results'] ?? null;
        $completed = is_array($results) && $results !== [] ? count($results) : null;
        $thresholds = $this->thresholds($game);

        // Slugs lagras med gemener (SlugRule) och MySQL jämför utan skiftläge; här likaså,
        // så att svaret blir detsamma oavsett databas.
        $definitions = AchievementDefinition::where('game_id', $game->id)
            ->whereIn('slug', array_map('mb_strtolower', $requested))
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (AchievementDefinition $d) => mb_strtolower($d->slug));
        $owned = UserAchievement::where('user_id', $request->user()->id)
            ->whereIn('achievement_definition_id', $definitions->pluck('id'))
            ->pluck('achievement_definition_id')
            ->flip();

        $unlocked = [];
        $rejected = [];
        $already = 0;
        foreach ($requested as $slug) {
            $definition = $definitions->get(mb_strtolower($slug));
            if ($definition === null) {
                continue;
            }
            // Redan upplåst räknas som det, oavsett tröskel (en ny enhet kan ha färre resultat).
            if ($owned->has($definition->id)) {
                $already++;

                continue;
            }
            if ($completed !== null && isset($thresholds[$slug]) && $completed < $thresholds[$slug]) {
                $rejected[] = $slug;

                continue;
            }

            $achievement = UserAchievement::firstOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'achievement_definition_id' => $definition->id,
                ],
                [
                    'unlocked_at' => now(),
                    'created_at' => now(),
                ]
            );

            if ($achievement->wasRecentlyCreated) {
                $achievement->load('achievementDefinition');
                $unlocked[] = new UserAchievementResource($achievement);
            } else {
                $already++; // samtidig rapport hann före
            }
        }

        return response()->json([
            'unlocked' => $unlocked,
            'already_unlocked' => $already,
            // Okända eller inaktiva slugs, och de som rimlighetskontrollen avvisade: synliga för spelet.
            'unknown' => array_values(array_filter($requested, fn (string $slug) => ! $definitions->has(mb_strtolower($slug)))),
            'rejected' => $rejected,
        ], 201);
    }

    /**
     * Trösklar (minsta antal resultat i molnsparningen) per slug, ur settings.achievement_thresholds.
     * Bara heltal > 0 gäller; en trasig post ger ingen kontroll för den sluggen, aldrig ett fel.
     *
     * @return array<string, int>
     */
    private function thresholds(Game $game): array
    {
        $raw = $game->settings['achievement_thresholds'] ?? null;
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $slug => $value) {
            if (is_int($value) && $value > 0) {
                $out[(string) $slug] = $value;
            } elseif (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $out[(string) $slug] = (int) $value;
            }
        }

        return $out;
    }
}
