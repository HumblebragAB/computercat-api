<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyContentPool;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyContentController extends Controller
{
    public function show(Game $game, string $poolKey): JsonResponse
    {
        return $this->contentForDate($game, $poolKey, now()->toDateString());
    }

    public function showDate(Game $game, string $poolKey, string $date): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'Invalid date format. Use YYYY-MM-DD.'], 422);
        }

        if ($date > now()->toDateString()) {
            return response()->json(['message' => 'Cannot request future dates.'], 403);
        }

        return $this->contentForDate($game, $poolKey, $date);
    }

    public function items(Request $request, Game $game, string $poolKey): JsonResponse
    {
        $validated = $request->validate([
            'count' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $pool = DailyContentPool::available($game, $poolKey, now()->toDateString())
            ->where('random_access', true)
            ->first();

        if (! $pool) {
            return response()->json(['message' => 'Content pool not found.'], 404);
        }

        return response()->json([
            'data' => $pool->randomItems((int) ($validated['count'] ?? 20)),
            'pool_key' => $poolKey,
        ]);
    }

    private function contentForDate(Game $game, string $poolKey, string $date): JsonResponse
    {
        $pool = DailyContentPool::available($game, $poolKey, $date)->first();

        if (! $pool) {
            return response()->json(['message' => 'Content pool not found.'], 404);
        }

        $content = $pool->contentForDate($date);

        return response()->json([
            'data' => $content,
            'date' => $date,
            'pool_key' => $poolKey,
        ]);
    }
}
