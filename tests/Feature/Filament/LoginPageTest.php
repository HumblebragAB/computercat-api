<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_laddar_sina_egna_filer_och_skyddar_katten_mot_morph(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('css/cc-login.css?v=', $html);
        $this->assertStringContainsString('css/cc-theme.css?v=', $html);
        $this->assertMatchesRegularExpression('#<script[^>]+js/cc-login\.js\?v=\d+"[^>]*defer#', $html);
        $this->assertMatchesRegularExpression('#id="cat-track"[^>]*wire:ignore|wire:ignore[^>]*id="cat-track"#', $html);
        $this->assertMatchesRegularExpression('#id="cat-track-bottom"[^>]*wire:ignore|wire:ignore[^>]*id="cat-track-bottom"#', $html);
        $this->assertStringContainsString('wire:submit="authenticate"', $html);
        $this->assertStringContainsString('class="cc-titlebar"', $html);
        $this->assertStringContainsString('Computer Cat</span>', $html);
        $this->assertStringNotContainsString('ready_', $html);
        $this->assertStringNotContainsString('cc-particle', $html);
        // Filament skriver själv ut <style>-block, så vi kontrollerar att login-vyns egen CSS/JS inte längre är inline.
        $this->assertStringNotContainsString('.cc-cat-body {', $html);
        $this->assertStringNotContainsString("getElementById('the-cat')", $html);
    }

    public function test_vanliga_adminsidor_laddar_inte_login_filerna(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        $this->get('/admin')->assertOk()->assertDontSee('cc-login.js', false)->assertDontSee('cc-login.css', false);
    }

    public function test_login_filerna_ligger_i_head_sa_att_sidan_inte_blinkar_ostylad(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $headSlut = strpos($html, '</head>');

        $this->assertLessThan($headSlut, strpos($html, 'css/cc-login.css'));
        $this->assertLessThan($headSlut, strpos($html, 'js/cc-login.js'));
        $this->assertSame(1, substr_count($html, 'css/cc-login.css'));
    }
}
