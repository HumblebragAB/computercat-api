<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

// Publik, bara läsning (spec 2c, "Byggguiden"). Sökvägen slås bara upp i
// manifestet; inget från anroparen når filsystemet.
class BuildGuideController extends Controller
{
    public function show(?string $version = null): JsonResponse
    {
        try {
            $guide = BuildGuide::publicManifest($version ?? BuildGuide::LATEST);
        } catch (BuildGuideNotFound) {
            return response()->json(['message' => 'Build guide version not found.'], 404);
        }

        return response()->json(['data' => $guide + ['available_versions' => BuildGuide::versions()]])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function file(string $version, string $path): Response|JsonResponse
    {
        try {
            $file = BuildGuide::file($version, $path);
        } catch (BuildGuideNotFound) {
            return response()->json(['message' => 'Build guide file not found.'], 404);
        }

        return response($file['content'], 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $file['sha256'],
            'ETag' => '"'.$file['sha256'].'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
