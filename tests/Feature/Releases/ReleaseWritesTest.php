<?php

namespace Tests\Feature\Releases;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Status på ett släpp ändras alltid via modellen (saving-kroken håller active_key i takt med status).
class ReleaseWritesTest extends TestCase
{
    public function test_app_code_never_mass_updates_or_deletes_releases(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();
            if (preg_match('/\bRelease::[^;]*->(update|delete|forceDelete|truncate|increment|decrement)\(/s', $code) === 1
                || preg_match('/DB::table\(\s*[\'"]releases[\'"]\s*\)/', $code) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Massuppdatering av releases går förbi active_key-kroken; uppdatera via modellen.');
    }

    public function test_the_guard_actually_matches_a_mass_update(): void
    {
        $bad = 'Release::where("game_id", 1)->update(["status" => "cancelled"]);';

        $this->assertSame(1, preg_match('/\bRelease::[^;]*->(update|delete|forceDelete|truncate|increment|decrement)\(/s', $bad));
    }
}
