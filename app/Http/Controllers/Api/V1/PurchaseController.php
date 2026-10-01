<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase management endpoints.
 *
 * Köp registreras bara av RevenueCat-webhooken. Det gamla
 * /purchases/verify lät klienten skapa egna köp utan kvitto och är borttaget.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::where('user_id', $request->user()->id)
            ->orderByDesc('purchased_at')
            ->get();

        return response()->json([
            'data' => $purchases->map(fn ($p) => [
                'product_id' => $p->product_id,
                'store' => $p->store,
                'status' => $p->status,
                'purchased_at' => $p->purchased_at,
            ]),
        ]);
    }
}
