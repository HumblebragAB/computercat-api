<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Matches\IllegalMove;
use App\Matches\MatchError;
use App\Matches\MatchService;
use App\Matches\MatchView;
use App\Matches\RulesetRegistry;
use App\Models\Game;
use App\Models\GameMatch;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Matcher över HTTP. Varje väg med {id} slår upp matchen på id och spel och kräver
 * att användaren sitter i den, annars samma 404 som för en match som inte finns.
 * Först därefter rörs matchen (touch, vy, drag).
 */
class MatchController extends Controller
{
    public function __construct(private readonly MatchService $matches) {}

    public function store(Request $request, Game $game): JsonResponse
    {
        $data = $request->validate([
            'ruleset' => ['required', 'string', 'max:32'],
            'options' => ['sometimes', 'array'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return $this->guard(function () use ($request, $game, $data) {
            ['match' => $match, 'token' => $token] = $this->matches->create(
                $game, $request->user(), $data['ruleset'], $data['options'] ?? [], $data['name'] ?? null,
            );

            return response()->json([
                'match' => MatchView::for($match->fresh(), 0, null),
                'join_token' => $token,
            ], 201);
        });
    }

    public function join(Request $request, Game $game): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return $this->guard(function () use ($request, $game, $data) {
            $match = $this->reload($this->matches->join($game, $request->user(), $data['token'], $data['name'] ?? null), $game);
            $seat = $this->matches->memberSeat($match, $request->user());

            return $this->respond($match, MatchView::for($match, $seat, null));
        });
    }

    public function active(Request $request, Game $game): JsonResponse
    {
        $userId = $request->user()->id;

        $matches = GameMatch::open()
            ->where('game_id', $game->id)
            ->whereHas('players', fn ($q) => $q->where('user_id', $userId))
            ->with(['players' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $matches->map(fn (GameMatch $match) => [
                'id' => $match->id,
                'ruleset' => $match->ruleset,
                'status' => $match->status,
                'your_seat' => (int) $match->players->first()->seat,
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Game $game, string $id): JsonResponse
    {
        $since = $this->since($request);
        [$match, $seat] = $this->resolve($request, $game, $id);

        $this->matches->touch($match, $request->user());

        $response = new JsonResponse;
        $response->setEtag(MatchView::etag($match));
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $this->guard(fn () => $response->setData(MatchView::for($match, $seat, $since)));
    }

    public function act(Request $request, Game $game, string $id): JsonResponse
    {
        $since = $this->since($request);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:32'],
            'payload' => ['present', 'array'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        [$match, $seat] = $this->resolve($request, $game, $id);

        return $this->guard(function () use ($request, $game, $match, $seat, $since, $data) {
            $match = $this->reload($this->matches->act($match, $request->user(), $data['type'], $data['payload'], (int) $data['version']), $game);

            return $this->respond($match, MatchView::for($match, $seat, $since));
        });
    }

    public function leave(Request $request, Game $game, string $id): JsonResponse
    {
        [$match, $seat] = $this->resolve($request, $game, $id);

        return $this->guard(function () use ($request, $game, $match, $seat) {
            $match = $this->reload($this->matches->leave($match, $request->user()), $game);

            return $this->respond($match, MatchView::for($match, $seat, null));
        });
    }

    public function rematch(Request $request, Game $game, string $id): JsonResponse
    {
        [$match, $seat] = $this->resolve($request, $game, $id);

        return $this->guard(function () use ($request, $game, $match, $seat) {
            $match = $this->reload($this->matches->rematch($match, $request->user()), $game);

            return $this->respond($match, MatchView::for($match, $seat, null));
        });
    }

    /** Ny inbjudningstoken för skaparen av en väntande match. Den gamla slutar gälla. */
    public function invite(Request $request, Game $game, string $id): JsonResponse
    {
        [$match, $seat] = $this->resolve($request, $game, $id);

        return $this->guard(function () use ($request, $game, $match, $seat) {
            $token = $this->matches->invite($match, $request->user());
            $match = $this->reload($match, $game);

            return $this->respond($match, [
                'match' => MatchView::for($match, $seat, null),
                'join_token' => $token,
            ]);
        });
    }

    /**
     * Matchen och användarens säte. Saknad, gallrad, i ett annat spel eller utan
     * användaren: samma 404, så att fallen inte går att skilja åt.
     *
     * @return array{0: GameMatch, 1: int}
     */
    private function resolve(Request $request, Game $game, string $id): array
    {
        // Spelarna laddas en gång och spelet är redan uppslaget, så vy, ETag, touch och
        // memberSeat inte frågar databasen igen.
        $match = GameMatch::whereKey($id)->where('game_id', $game->id)->with('players')->first();
        $seat = $match === null ? null : $this->matches->memberSeat($match->setRelation('game', $game), $request->user());

        if ($seat === null) {
            abort(404);
        }

        return [$match, $seat];
    }

    /** Matchen efter en ändring, läst om med spelare och med det redan kända spelet. */
    private function reload(GameMatch $match, Game $game): GameMatch
    {
        return $match->fresh(['players'])->setRelation('game', $game);
    }

    /** Svar med samma ETag som en poll skulle ge, så klienten kan skicka den som If-None-Match. */
    private function respond(GameMatch $match, array $body): JsonResponse
    {
        return response()->json($body)->setEtag(MatchView::etag($match));
    }

    /** Frågesträngens since, valideras som heltal ≥ 0. */
    private function since(Request $request): ?int
    {
        $data = Validator::make(
            ['since' => $request->query('since')],
            ['since' => ['nullable', 'integer', 'min:0']],
        )->validate();

        return $data['since'] === null ? null : (int) $data['since'];
    }

    /** Översätter matchgrundens kodade fel till {message, code, ...}. */
    private function guard(Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (MatchError $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra, $e->status);
        } catch (IllegalMove $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (AuthorizationException $e) {
            if ($e->getMessage() !== RulesetRegistry::NOT_ALLOWED) {
                throw $e;
            }

            return response()->json([
                'message' => 'Regeluppsättningen finns inte i det här spelet.',
                'code' => RulesetRegistry::NOT_ALLOWED,
            ], 403);
        }
    }
}
