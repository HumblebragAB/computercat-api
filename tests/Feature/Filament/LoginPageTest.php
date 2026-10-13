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
        $this->assertStringContainsString('ready_', $html);
        $this->assertStringNotContainsString('cc-particle', $html);
        // Filament skriver själv ut <style>-block, så vi kontrollerar att login-vyns egen CSS/JS inte längre är inline.
        $this->assertStringNotContainsString('.cc-cat-body {', $html);
        $this->assertStringNotContainsString("getElementById('the-cat')", $html);
    }

    public function test_vanliga_adminsidor_laddar_inte_login_skriptet(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        $this->get('/admin')->assertOk()->assertDontSee('cc-login.js', false);
    }
}
