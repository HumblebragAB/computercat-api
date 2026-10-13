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

    public function test_adminsidor_hamtar_inget_fran_andra_hostar(): void
    {
        $this->actingAs(User::factory()->staff()->create(['name' => 'Åsa <b>Öberg</b>']));

        $html = $this->get('/admin')->assertOk()->getContent();

        // src/href mot andra hostar än appens egen (t.ex. ui-avatars.com för standardavataren).
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        preg_match_all('#(?:src|href)="(https?://[^"/]+)#', $html, $m);
        foreach (array_unique($m[1]) as $origin) {
            $this->assertSame($appHost, parse_url($origin, PHP_URL_HOST), "laddar från $origin");
        }
        $this->assertStringContainsString('src="data:image/svg+xml', $html);
        $this->assertStringNotContainsString('<b>Öberg', $html);
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

    public function test_info_fargen_ar_temad_och_inte_filament_bla(): void
    {
        // Panelens färger registreras först när panelen serveras, så läs dem från panelen.
        $info = \Filament\Support\Colors\Color::hex('#8fb3a8')[500];
        $this->assertSame($info, filament()->getPanel('admin')->getColors()['info'][500]);

        $this->assertSame('143, 179, 168', $info);
    }

    public function test_mork_knapptext_klarar_aa_pa_alla_fargade_knappar(): void
    {
        foreach (['#f2b544', '#f6c76e', '#e8664a', '#9fd36b', '#f08a24', '#8fb3a8'] as $bg) {
            $this->assertGreaterThanOrEqual(4.5, contrast('#0d0b08', $bg), "mörk text på $bg");
        }
    }
}
