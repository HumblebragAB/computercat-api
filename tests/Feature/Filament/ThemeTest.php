<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Tests\Support\contrast;

require_once __DIR__.'/../../Support/contrast.php';

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_adminsidor_laddar_temat_och_inget_fran_tredje_part(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('css/cc-theme.css?v=', $html);
        $this->assertStringContainsString('css/cc-admin.css?v=', $html);
        $this->assertStringContainsString('js/cc-eggs.js?v=', $html);
        $this->assertStringContainsString("'IBM Plex Mono'", $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_gray_skalan_klarar_aa_dar_filament_anvander_den_som_text(): void
    {
        $g = AdminPanelProvider::GRAY;

        // Filament i mörkt läge: dämpad text gray-400/500 på gray-900/950.
        foreach ([400, 500] as $fg) {
            foreach ([900, 950] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, contrast($g[$fg], $g[$bg]), "gray-$fg på gray-$bg");
            }
        }
    }

    public function test_mork_knapptext_klarar_aa_pa_alla_fargade_knappar(): void
    {
        foreach (['#f2b544', '#f6c76e', '#e8664a', '#9fd36b', '#f08a24'] as $bg) {
            $this->assertGreaterThanOrEqual(4.5, contrast('#0d0b08', $bg), "mörk text på $bg");
        }
    }
}
