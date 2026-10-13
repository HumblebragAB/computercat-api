<?php

namespace Tests\Feature\Mcp;

use App\Models\StaffUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $attrs = []): User
    {
        return User::factory()->staff()->create($attrs);
    }

    private function mcpCall(User $user)
    {
        Passport::actingAs(StaffUser::find($user->id), ['mcp:use'], 'mcp');

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list_games', 'arguments' => (object) []],
        ]);
    }

    public function test_staff_flags_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['is_staff' => true, 'is_platform_admin' => true]);

        $this->assertFalse($user->fresh()->is_staff);
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_staff_flags_and_helpers_match_on_user_and_staff_user(): void
    {
        $admin = $this->staff(['is_platform_admin' => true]);
        $staff = $this->staff();
        $banned = User::factory()->staff(admin: true)->create(['is_banned' => true]);

        foreach ([$admin, StaffUser::find($admin->id)] as $model) {
            $this->assertTrue($model->isStaff());
            $this->assertTrue($model->isPlatformAdmin());
        }
        foreach ([$staff, StaffUser::find($staff->id)] as $model) {
            $this->assertTrue($model->isStaff());
            $this->assertFalse($model->isPlatformAdmin());
        }
        foreach ([$banned, StaffUser::find($banned->id)] as $model) {
            $this->assertFalse($model->isStaff());
            $this->assertFalse($model->isPlatformAdmin());
        }
    }

    public function test_panel_access_follows_flag_not_email(): void
    {
        $panel = Filament::getDefaultPanel();
        $player = User::factory()->create(['email' => 'mattias@humblebrag.se', 'is_anonymous' => false]);

        $this->assertFalse($player->canAccessPanel($panel));
        $this->assertTrue($this->staff()->canAccessPanel($panel));
        $this->assertFalse($this->staff(['is_banned' => true])->canAccessPanel($panel));
    }

    public function test_non_staff_and_banned_are_denied_on_mcp(): void
    {
        $this->mcpCall(User::factory()->create())->assertForbidden();
        $this->mcpCall($this->staff(['is_banned' => true]))->assertForbidden();
        $this->mcpCall($this->staff())->assertOk();

        $this->assertSame(2, \App\Models\McpAuditLog::where('outcome', 'denied')->count());
    }

    public function test_denied_mcp_call_is_still_403_when_audit_write_fails(): void
    {
        \Illuminate\Support\Facades\Schema::drop('mcp_audit_logs');

        $this->mcpCall(User::factory()->create())->assertForbidden();
    }

    public function test_revoking_staff_denies_next_call(): void
    {
        $user = $this->staff();
        $this->mcpCall($user)->assertOk();

        $user->forceFill(['is_staff' => false])->save();

        $this->mcpCall($user)->assertForbidden();
    }

    private function authorizeUrl(string $clientName = 'Claude'): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: $clientName, redirectUris: ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        return route('passport.authorizations.authorize', [
            'client_id' => $client->getKey(), 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 's',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function test_non_staff_cannot_reach_or_approve_authorize(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->authorizeUrl())->assertForbidden();
        $this->post(route('passport.authorizations.approve'))->assertForbidden();
        $this->delete(route('passport.authorizations.deny'))->assertForbidden();
    }

    public function test_banned_staff_cannot_reach_authorize(): void
    {
        $this->actingAs($this->staff(['is_banned' => true]));

        $this->get($this->authorizeUrl())->assertForbidden();
    }

    public function test_guest_is_sent_to_login_and_back_to_authorize(): void
    {
        $url = $this->authorizeUrl();

        $this->get($url)->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/admin/login');
        // Laravel sorterar query-parametrarna i fullUrl(); jämför samma adress normaliserad.
        $this->assertSame(Request::create($url)->fullUrl(), session('url.intended'));

        // Efter inloggning via Filament ska intended leda tillbaka till authorize.
        $this->withoutBuiltAssets();
        $this->actingAs($this->staff());
        $this->get(redirect()->intended('/admin')->getTargetUrl())->assertOk()->assertSee('Claude');
    }

    // Produktionen bygger inga Vite-assets. Godkännandevyn måste renderas utan
    // public/build/manifest.json, även om någon har byggt lokalt.
    private function withoutBuiltAssets(): void
    {
        $this->app->usePublicPath(sys_get_temp_dir().'/computercat-no-build-'.uniqid());
    }

    public function test_authorize_page_renders_without_vite_and_escapes_client_name(): void
    {
        $this->withoutBuiltAssets();
        $user = $this->staff();
        $this->actingAs($user);

        // Namnet sätts av vem som helst via dynamisk registrering.
        $response = $this->get($this->authorizeUrl('<b>x</b>'))->assertOk();

        $response->assertSee('&lt;b&gt;x&lt;/b&gt;', false);
        $response->assertDontSee('<b>x</b>', false);
        $response->assertSee($user->email);
        $response->assertSee('name="auth_token"', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee('name="_method" value="DELETE"', false);
        $response->assertSee('action="'.route('passport.authorizations.approve').'"', false);
        $response->assertDontSee('/build/', false);
    }
}
