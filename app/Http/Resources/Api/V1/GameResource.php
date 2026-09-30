<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            // settings lämnas medvetet ute: fältet bär hemligheter per spel
            // (t.ex. revenuecat.webhook_secret) och den här resursen är publik.
            'leaderboard_types' => LeaderboardTypeResource::collection(
                $this->whenLoaded('leaderboardTypes')
            ),
        ];
    }
}
