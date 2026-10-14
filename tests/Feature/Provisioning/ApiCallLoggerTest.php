<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Http\ApiCallLogger;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class ApiCallLoggerTest extends ProvisioningTestCase
{
    public function test_log_has_method_url_status_and_redacted_bodies(): void
    {
        Http::fake(['*' => Http::response(['message' => 'bad', 'token' => 'svar-token-hemlig'], 422)]);
        $response = Http::post('https://coolify.test/api/v1/applications/private-github-app');
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message;
        });

        ApiCallLogger::log('Coolify', 'POST', 'https://coolify.test/api/v1/applications/private-github-app', ['name' => 'spel-x', 'http_basic_auth_password' => 'losen-hemligt'], $response);

        $this->assertCount(1, $logged);
        $context = json_encode($logged[0]->context);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertStringContainsString('"method":"POST"', $context);
        $this->assertStringContainsString('"status":422', $context);
        $this->assertStringContainsString('spel-x', $context);
        $this->assertStringNotContainsString('losen-hemligt', $context);
        $this->assertStringNotContainsString('svar-token-hemlig', $context);
    }

    public function test_exception_message_has_path_not_host_and_is_safe(): void
    {
        $e = new ExternalApiException('Coolify', 'GET', '/applications/abc', 500, '{"logs":"[REDACTED]"} '.ProvisioningFakes::COOLIFY_TOKEN);

        $this->assertSame('Coolify GET /applications/abc returned 500: {"logs":"[REDACTED]"} [REDACTED]', $e->getMessage());
        $this->assertStringNotContainsString('coolify.test', $e->getMessage());
    }
}
