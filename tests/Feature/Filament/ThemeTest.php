<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Support\Colors\Color;
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

        // Filament i ljust läge: dämpad text och platshållare gray-400/500/600 på vitt och gray-50.
        foreach ([400, 500, 600] as $fg) {
            foreach (['#ffffff', $g[50]] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, contrast($g[$fg], $bg), "gray-$fg på $bg");
            }
        }
    }

    public function test_alla_semantiska_farger_ar_blaeck(): void
    {
        // Panelens färger registreras först när panelen serveras, så läs dem från panelen.
        $farger = filament()->getPanel('admin')->getColors();
        foreach (['primary', 'danger', 'success', 'warning', 'info'] as $c) {
            $this->assertSame(Color::hex('#111111')[500], $farger[$c][500], $c);
        }
    }

    public function test_panelen_ar_alltid_ljus(): void
    {
        $this->assertFalse(filament()->getPanel('admin')->hasDarkMode());
    }

    public function test_inverterad_text_klarar_aa(): void
    {
        $this->assertGreaterThanOrEqual(4.5, contrast('#fbfaf6', '#111111'));
    }
}
