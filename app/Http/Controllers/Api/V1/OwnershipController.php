<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-authoritative ownership check.
 *
 * Reads the products table to map verified purchases to game content grants.
 * Theme pack contents come from games.settings.theme_packs for per-game flexibility.
 */
class OwnershipController extends Controller
{
    public function show(Request $request, Game $game): JsonResponse
    {
        $themePacks = $game->settings['theme_packs'] ?? [];

        // Fetch all products for this game keyed by product_id
        $products = Product::where('game_id', $game->id)
            ->get()
            ->keyBy('product_id');

        $purchasedIds = Purchase::where('user_id', $request->user()->id)
            ->where('game_id', $game->id)
            ->whereIn('status', ['verified', 'pending'])
            ->get()
            ->filter(fn (Purchase $purchase) => $purchase->grantsAccess())
            ->pluck('product_id')
            ->toArray();

        $ownedPacks = [];
        $ownedThemes = [];
        $isSupporter = false;

        foreach ($purchasedIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            switch ($product->grant_type) {
                case 'pack':
                    if ($product->grant_id) {
                        $ownedPacks[] = $product->grant_id;
                    }
                    break;
                case 'theme_pack':
                    if ($product->grant_id) {
                        $themes = $this->resolveThemePack($product->grant_id, $themePacks);
                        $ownedThemes = array_merge($ownedThemes, $themes);
                    }
                    break;
                case 'supporter':
                    $isSupporter = true;
                    break;
            }
        }

        return response()->json([
            'data' => [
                'owned_packs' => array_values(array_unique($ownedPacks)),
                'owned_themes' => array_values(array_unique($ownedThemes)),
                'is_supporter' => $isSupporter,
            ],
        ]);
    }

    /**
     * Resolve theme pack ID to individual theme IDs.
     * Handles both array and comma-separated string formats from settings.
     */
    private function resolveThemePack(string $packId, array $themePacks): array
    {
        $themes = $themePacks[$packId] ?? [];

        if (is_string($themes)) {
            return array_map('trim', explode(',', $themes));
        }

        return is_array($themes) ? $themes : [];
    }
}
