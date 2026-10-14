<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalHttpGuardTest extends TestCase
{
    public function test_delete_to_github_is_stopped_and_recorded_even_when_faked(): void
    {
        Http::fake();

        try {
            Http::delete('https://api.github.com/repos/HumblebragAB/kattsvep');
            $this->fail('DELETE mot GitHub släpptes igenom.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('DELETE mot api.github.com', $e->getMessage());
        }

        $this->assertSame(['https://api.github.com/repos/HumblebragAB/kattsvep'], TestCase::$githubDeletes);
        // Nollställs så att tearDown inte fäller testet; vakten är bevisad.
        TestCase::$githubDeletes = [];
    }

    public function test_other_methods_and_hosts_pass(): void
    {
        Http::fake();

        Http::get('https://api.github.com/repos/HumblebragAB/kattsvep');
        Http::delete('https://coolify.test/api/v1/applications/x');

        Http::assertSentCount(2);
    }

    public function test_unfaked_requests_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        Http::get('https://example.invalid/');
    }
}
