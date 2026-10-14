<?php

namespace Tests\Feature\Api\V1;

use App\Services\BuildGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildGuideApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_public_and_cacheable(): void
    {
        $response = $this->getJson('/api/v1/build-guide')->assertOk();

        $this->assertSame(BuildGuide::LATEST, $response->json('data.version'));
        $this->assertSame(BuildGuide::publicManifest(BuildGuide::LATEST)['files'], $response->json('data.files'));
        $this->assertSame(BuildGuide::versions(), $response->json('data.available_versions'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/build-guide/v1')->assertOk()->assertJsonPath('data.version', 'v1');
    }

    public function test_file_is_served_as_plain_text_with_sha256(): void
    {
        $response = $this->get('/api/v1/build-guide/v1/files/package-lock.json')->assertOk();

        $this->assertSame(BuildGuide::file('v1', 'package-lock.json')['content'], $response->getContent());
        $this->assertSame(hash('sha256', $response->getContent()), $response->headers->get('X-Content-SHA256'));
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->get('/api/v1/build-guide/v1/files/src/lib/computercat/index.ts')->assertOk();
        $this->get('/api/v1/build-guide/v1/files/.gitignore')->assertOk();
    }

    public function test_unknown_files_versions_and_traversal_are_404(): void
    {
        $this->getJson('/api/v1/build-guide/v9')->assertNotFound()->assertJsonPath('message', 'Build guide version not found.');
        $this->getJson('/api/v1/build-guide/v1/files/README.md')->assertNotFound()->assertJsonPath('message', 'Build guide file not found.');
        $this->getJson('/api/v1/build-guide/v1/files/..%2F..%2F..%2F.env')->assertNotFound();
        $this->getJson('/api/v1/build-guide/v1/files/files/package.json')->assertNotFound();
        $this->getJson('/api/v1/build-guide/x1')->assertNotFound();
    }

    public function test_routes_have_their_own_rate_limit(): void
    {
        $this->assertNotNull(RateLimiter::limiter('build-guide'));
        foreach (['/api/v1/build-guide', '/api/v1/build-guide/v1', '/api/v1/build-guide/v1/files/package.json'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri));
            $this->assertContains('throttle:build-guide', $route->gatherMiddleware(), $uri);
        }
    }

    public function test_31st_request_within_a_minute_is_throttled(): void
    {
        RateLimiter::clear('build-guide:127.0.0.1');
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/build-guide')->assertOk();
        }

        $this->getJson('/api/v1/build-guide')->assertStatus(429);
    }

    // Ett internt fel (här: APP_URL saknas, så nedladdningsadressen kan inte byggas)
    // ger 500 utan undantagstext eller stack trace i svaret.
    public function test_internal_error_returns_500_without_details(): void
    {
        config(['app.debug' => false, 'app.url' => '']);

        $response = $this->getJson('/api/v1/build-guide')->assertStatus(500);

        $body = $response->getContent();
        $this->assertStringNotContainsString('APP_URL', $body);
        $this->assertStringNotContainsString('BuildGuide', $body);
        $this->assertStringNotContainsString('trace', $body);
    }

    // Spec delprojekt 3: en version utan FROZEN-post syns inte och serveras inte.
    public function test_unpublished_version_is_not_listed_or_served(): void
    {
        $root = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $root);
        try {
            File::copyDirectory("{$root}/v1", "{$root}/v99");
            $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
            $manifest['version'] = 'v99';
            file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
            config(['byggguide.path' => $root]);

            $this->assertSame('v99', BuildGuide::manifest('v99')['version']);
            $this->getJson('/api/v1/build-guide/v99')->assertNotFound()->assertJsonPath('message', 'Build guide version not found.');
            $this->getJson('/api/v1/build-guide/v99/files/package.json')->assertNotFound()->assertJsonPath('message', 'Build guide file not found.');
            $this->assertNotContains('v99', $this->getJson('/api/v1/build-guide')->json('data.available_versions'));
        } finally {
            // Bara katalogen som testet skapade.
            File::deleteDirectory($root);
        }
    }
}
