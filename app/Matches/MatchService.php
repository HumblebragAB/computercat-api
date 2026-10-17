<?php

namespace App\Matches;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matchgrunden: skapa, gå med, drag, lämna, revansch. Varje ändring sker i en
 * transaktion med radlås på matchen, och varje ändring som klienten ska se ökar
 * version (annars ger pollen 304). Attribut byggs alltid uttryckligen här, aldrig
 * från request-input.
 */
class MatchService
{
    public const MAX_OPEN_PER_GAME = 5;

    public const WAITING_MINUTES = 15;

    public const ACTIVE_MINUTES = 30;

    /** last_seen_at skrivs högst så här ofta per spelare. */
    public const TOUCH_SECONDS = 5;

    private const NAME_PATTERN = '/\A[^\p{C}]{1,20}\z/u';

    /** @return array{match: GameMatch, token: string} */
    public function create(Game $game, User $user, string $ruleset, array $options, ?string $name): array
    {
        $this->assertBelowCap($game, $user);
        $rules = RulesetRegistry::for($game, $ruleset);
        $options = $rules->validateOptions($options);
        $name = $this->name($name, 0);

        $token = $this->newToken();

        $match = DB::transaction(function () use ($game, $user, $ruleset, $options, $name, $rules, $token) {
            $match = new GameMatch;
            $match->game_id = $game->id;
            $match->ruleset = $ruleset;
            $match->options = $options;
            $match->status = GameMatch::STATUS_WAITING;
            $match->join_token_hash = hash('sha256', $token);
            $match->state = $rules->initialState($options);
            $match->version = 1;
            $match->expires_at = now()->addMinutes(self::WAITING_MINUTES);
            $match->save();

            $this->addPlayer($match, $user, 0, $name);

            return $match;
        });

        return ['match' => $match, 'token' => $token];
    }

    public function join(Game $game, User $user, string $token, ?string $name): GameMatch
    {
        $name = $this->name($name, 1);

        return DB::transaction(function () use ($game, $user, $token, $name) {
            $match = GameMatch::where('join_token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($match === null
                || (int) $match->game_id !== (int) $game->id
                || $match->status !== GameMatch::STATUS_WAITING
                || ($match->expires_at !== null && $match->expires_at->lessThanOrEqualTo(now()))) {
                throw MatchError::inviteExpired();
            }

            if ($this->memberSeat($match, $user) !== null) {
                throw MatchError::ownMatch();
            }

            $this->assertBelowCap($game, $user);

            $this->addPlayer($match, $user, 1, $name);

            $rules = RulesetRegistry::for($game, $match->ruleset);
            $ctx = new MatchContext($rules, $match->options, $match->state, [null, null]);
            $rules->start($ctx);

            $match->join_token_hash = null;
            $match->status = GameMatch::STATUS_ACTIVE;
            $match->expires_at = now()->addMinutes(self::ACTIVE_MINUTES);

            $this->persist($match, $ctx, $this->playersOf($match));

            return $match;
        });
    }

    /**
     * Ny inbjudningstoken för en väntande match, t.ex. när skaparen laddat om och tappat
     * QR-koden. Bara skaparen (säte 0) och bara i waiting. Den gamla token slutar gälla,
     * väntetiden börjar om. Ingen händelse skrivs, men version ökar.
     */
    public function invite(GameMatch $match, User $user): string
    {
        return DB::transaction(function () use ($match, $user) {
            $match = $this->lock($match);
            $seat = $this->requireSeat($match, $user);

            if ($seat !== 0 || $match->status !== GameMatch::STATUS_WAITING) {
                throw MatchError::wrongPhase();
            }

            $token = $this->newToken();
            $match->join_token_hash = hash('sha256', $token);
            $match->expires_at = now()->addMinutes(self::WAITING_MINUTES);
            $match->version = $match->version + 1;
            $match->save();

            return $token;
        });
    }

    public function act(GameMatch $match, User $user, string $type, array $payload, int $version): GameMatch
    {
        return DB::transaction(function () use ($match, $user, $type, $payload, $version) {
            $match = $this->lock($match);
            $seat = $this->requireSeat($match, $user);

            if ($match->status !== GameMatch::STATUS_ACTIVE) {
                throw MatchError::wrongPhase();
            }
            if ($match->version !== $version) {
                throw MatchError::stale($match->version);
            }

            $players = $this->playersOf($match);
            $rules = RulesetRegistry::for($match->game, $match->ruleset);
            $hidden = [];
            foreach ($players as $player) {
                $hidden[$player->seat] = $player->hidden_state;
            }
            $ctx = new MatchContext($rules, $match->options, $match->state, $hidden);

            // IllegalMove bubblar ut innan något skrivits; transaktionen rullas tillbaka ändå.
            $rules->act($ctx, $seat, $type, $payload);

            $match->last_action_at = now();
            $match->expires_at = now()->addMinutes(self::ACTIVE_MINUTES);
            if ($ctx->isFinished()) {
                $match->status = GameMatch::STATUS_FINISHED;
            }

            $this->persist($match, $ctx, $players);

            if ($ctx->isFinished()) {
                $this->clearHidden($players);
            }

            return $match;
        });
    }

    public function leave(GameMatch $match, User $user): GameMatch
    {
        return DB::transaction(function () use ($match, $user) {
            $match = $this->lock($match);
            $seat = $this->requireSeat($match, $user);

            if (! in_array($match->status, [GameMatch::STATUS_WAITING, GameMatch::STATUS_ACTIVE], true)) {
                return $match;
            }

            $match->status = GameMatch::STATUS_ABANDONED;
            $match->join_token_hash = null;
            $this->logBaseEvent($match, 'left', $seat, []);
            $match->save();

            $this->clearHidden($this->playersOf($match));

            return $match;
        });
    }

    /**
     * Överger en öppen match vars tid gått ut. Läser om och kontrollerar under lås, så
     * ett drag som hann flytta expires_at vinner. Returnerar om matchen gick ut.
     */
    public function expire(GameMatch $match): bool
    {
        return DB::transaction(function () use ($match) {
            $match = $this->lock($match);

            if (! in_array($match->status, [GameMatch::STATUS_WAITING, GameMatch::STATUS_ACTIVE], true)
                || $match->expires_at === null
                || ! $match->expires_at->lessThan(now())) {
                return false;
            }

            $match->status = GameMatch::STATUS_ABANDONED;
            $match->join_token_hash = null;
            $this->logBaseEvent($match, 'expired', null, []);
            $match->save();

            $this->clearHidden($this->playersOf($match));

            return true;
        });
    }

    /** Returnerar den gamla matchen. När båda begärt pekar dess rematch-händelse ut den nya. */
    public function rematch(GameMatch $match, User $user): GameMatch
    {
        return DB::transaction(function () use ($match, $user) {
            $match = $this->lock($match);
            $seat = $this->requireSeat($match, $user);

            if ($match->status !== GameMatch::STATUS_FINISHED) {
                throw MatchError::wrongPhase();
            }

            $players = $this->playersOf($match);
            $me = $players->firstWhere('seat', $seat);
            if ($me->rematch_requested) {
                return $match;
            }

            $me->rematch_requested = true;
            $me->save();
            $this->logBaseEvent($match, 'rematch_requested', $seat, []);

            if ($players->count() === 2 && $players->every(fn (GameMatchPlayer $p) => $p->rematch_requested)) {
                // Medvetet ingen kontroll mot taket på fem: båda har sagt ja, och taket är ett
                // skydd mot skräpmatcher. Den gamla matchen är finished och räknas inte längre.
                $new = $this->startRematch($match, $players);
                $this->logBaseEvent($match, 'rematch', null, ['match_id' => $new->id]);
            }

            $match->save();

            return $match;
        });
    }

    public function touch(GameMatch $match, User $user): void
    {
        $player = $match->players->firstWhere('user_id', $user->id);
        if ($player === null) {
            return;
        }

        if ($player->last_seen_at === null
            || $player->last_seen_at->lessThanOrEqualTo(now()->subSeconds(self::TOUCH_SECONDS))) {
            $player->last_seen_at = now();
            $player->save();
        }
    }

    public function memberSeat(GameMatch $match, User $user): ?int
    {
        $seat = $match->relationLoaded('players')
            ? $match->players->firstWhere('user_id', $user->id)?->seat
            : $match->players()->where('user_id', $user->id)->value('seat');

        return $seat === null ? null : (int) $seat;
    }

    /** 32 slumpbytes som base64url utan utfyllnad. Bara sha256 av den lagras. */
    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function startRematch(GameMatch $old, Collection $oldPlayers): GameMatch
    {
        $rules = RulesetRegistry::for($old->game, $old->ruleset);

        $new = new GameMatch;
        $new->game_id = $old->game_id;
        $new->ruleset = $old->ruleset;
        $new->options = $old->options;
        $new->status = GameMatch::STATUS_ACTIVE;
        $new->join_token_hash = null;
        $new->state = $rules->initialState($old->options);
        $new->version = 0; // persist() efter start ger version 1.
        $new->expires_at = now()->addMinutes(self::ACTIVE_MINUTES);
        $new->rematch_of = $old->id;
        $new->save();

        foreach ($oldPlayers as $player) {
            $this->addPlayer($new, $player->user, $player->seat, $player->name);
        }

        $ctx = new MatchContext($rules, $new->options, $new->state, [null, null]);
        $rules->start($ctx);
        $this->persist($new, $ctx, $this->playersOf($new));

        return $new;
    }

    /**
     * Skriver ett drags resultat: händelser med löpande seq, ändrad dold state,
     * publik state och en version upp. Sparar matchen.
     */
    private function persist(GameMatch $match, MatchContext $ctx, Collection $players): void
    {
        $seq = $this->lastSeq($match);
        foreach ($ctx->emitted() as $event) {
            $this->writeEvent($match, ++$seq, $event['type'], $event['seat'], $event['payload']);
        }

        foreach ($ctx->hiddenChanges() as $seat => $hidden) {
            $player = $players->firstWhere('seat', $seat);
            if ($player === null) {
                throw new \LogicException("Rules changed hidden state for empty seat {$seat}.");
            }
            $player->hidden_state = $hidden;
            $player->save();
        }

        $match->state = $ctx->state();
        $match->version = $match->version + 1;
        $match->save();
    }

    /** Grundens egna händelser (left, rematch_requested, rematch) går inte genom reglerna. Ökar version, sparar inte. */
    private function logBaseEvent(GameMatch $match, string $type, ?int $seat, array $payload): void
    {
        $this->writeEvent($match, $this->lastSeq($match) + 1, $type, $seat, $payload);
        $match->version = $match->version + 1;
    }

    private function writeEvent(GameMatch $match, int $seq, string $type, ?int $seat, array $payload): void
    {
        $event = new GameMatchEvent;
        $event->match_id = $match->id;
        $event->seq = $seq;
        $event->type = $type;
        $event->seat = $seat;
        $event->payload = $payload;
        $event->save();
    }

    private function lastSeq(GameMatch $match): int
    {
        return (int) GameMatchEvent::where('match_id', $match->id)->max('seq');
    }

    private function clearHidden(Collection $players): void
    {
        foreach ($players as $player) {
            if ($player->hidden_state !== null) {
                $player->hidden_state = null;
                $player->save();
            }
        }
    }

    private function addPlayer(GameMatch $match, User $user, int $seat, string $name): GameMatchPlayer
    {
        $player = new GameMatchPlayer;
        $player->match_id = $match->id;
        $player->user_id = $user->id;
        $player->seat = $seat;
        $player->name = $name;
        $player->hidden_state = null;
        $player->save();

        return $player;
    }

    /** @return Collection<int, GameMatchPlayer> */
    private function playersOf(GameMatch $match): Collection
    {
        return GameMatchPlayer::where('match_id', $match->id)->orderBy('seat')->get();
    }

    /** Läser om matchen med radlås. Anroparens instans kan vara inaktuell. */
    private function lock(GameMatch $match): GameMatch
    {
        return GameMatch::whereKey($match->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Den som inte sitter i matchen ska inte kunna skilja den från en som inte finns. */
    private function requireSeat(GameMatch $match, User $user): int
    {
        return $this->memberSeat($match, $user) ?? throw (new ModelNotFoundException)->setModel(GameMatch::class);
    }

    private function assertBelowCap(Game $game, User $user): void
    {
        $open = GameMatch::open()
            ->where('game_id', $game->id)
            ->whereHas('players', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        if ($open >= self::MAX_OPEN_PER_GAME) {
            throw MatchError::tooManyActive();
        }
    }

    private function name(?string $name, int $seat): string
    {
        if ($name === null) {
            return 'Spelare '.($seat + 1);
        }

        $trimmed = trim($name);
        if (preg_match(self::NAME_PATTERN, $trimmed) !== 1) {
            throw ValidationException::withMessages([
                'name' => 'Namnet ska vara 1–20 tecken utan kontrolltecken.',
            ]);
        }

        return $trimmed;
    }
}
