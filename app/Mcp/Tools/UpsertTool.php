<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Upsert på identitetsfältet (slug/pool_key/key). Reglerna kommer från samma
// regelklass som Filament använder, så panelen och MCP kan inte glida isär.
// Inget raderingsverktyg finns; is_active = false stänger av en post.
abstract class UpsertTool extends GameTool
{
    /** @return class-string */
    abstract protected function rulesClass(): string;

    abstract protected function relation(Game $game): HasMany;

    protected function rulesFor(Game $game, ?Model $existing, array $input): array
    {
        return $this->rulesClass()::rules($game, $existing);
    }

    /** Sista anpassning av validerade värden före sparning. */
    protected function prepare(array $validated): array
    {
        return $validated;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $class = $this->rulesClass();
        // Bara modellens egna fält; game_id, id och annat okänt följer inte med.
        $input = array_intersect_key($args, array_flip($class::FIELDS));
        $identity = $input[$class::IDENTITY] ?? null;

        // Uppslaget går via spelets relation, så en post i ett annat spel
        // med samma identitet kan aldrig träffas.
        $existing = is_string($identity)
            ? $this->relation($game)->where($class::IDENTITY, $identity)->first()
            : null;

        $validator = Validator::make($input, $this->rulesFor($game, $existing, $input));
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $record = $existing ?? $this->relation($game)->make();
        $record->fill($this->prepare($validator->validated()))->save();

        return $this->json(['saved' => true, 'created' => $existing === null, $class::IDENTITY => $identity]);
    }
}
