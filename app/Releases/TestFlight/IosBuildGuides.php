<?php

namespace App\Releases\TestFlight;

use App\Services\BuildGuide;

// Tunn, injicerbar väg till BuildGuide::supportsIos, så att släpptesterna kan köras innan
// någon låst version stöder iOS. Medvetet inte final.
class IosBuildGuides
{
    public function supports(string $version): bool
    {
        return BuildGuide::supportsIos($version);
    }
}
