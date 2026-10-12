<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\RemoteConfig;
use App\Services\Glosis\GlosisSettings;
use Illuminate\Http\JsonResponse;

class RemoteConfigController extends Controller
{
    public function index(Game $game): JsonResponse
    {
        $configs = RemoteConfig::where('game_id', $game->id)
            ->where('is_active', true)
            ->get();

        $data = [];
        foreach ($configs as $config) {
            $data[$config->key] = $config->typedValue();
        }

        // Glosis gratisnivå (Filament, settings.free.enabled). Appen döljer gratislöftena när den är av;
        // servern avgör ändå (FreeGate). Reserverat namn: en RemoteConfig-rad med samma nyckel skrivs över.
        if ($game->slug === 'glosis') {
            $data['glosis_free_enabled'] = GlosisSettings::for($game)->freeEnabled();
        }

        // Cast to object so empty result serializes as {} not []
        return response()->json(['data' => (object) $data]);
    }
}
