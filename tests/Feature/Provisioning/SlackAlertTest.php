<?php

namespace Tests\Feature\Provisioning;

use App\Support\SlackAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class SlackAlertTest extends ProvisioningTestCase
{
    public function test_sends_text_to_webhook(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);

        $this->assertSame(SlackAlert::SENT, app(SlackAlert::class)->send('k1', 'Hej'));

        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && $r->method() === 'POST' && $r->data() === ['text' => 'Hej']);
    }

    public function test_same_key_is_sent_once_per_day(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
        $slack = app(SlackAlert::class);

        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'A'));
        $this->assertSame(SlackAlert::DUPLICATE, $slack->send('k1', 'B'));
        $this->assertSame(SlackAlert::SENT, $slack->send('k2', 'C'));
        $this->travel(1)->days();
        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'D'));

        Http::assertSentCount(3);
    }

    public function test_failure_is_logged_without_url_and_not_deduped(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::sequence()->push('nope', 500)->push('ok', 200)]);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m;
        });
        $slack = app(SlackAlert::class);

        $this->assertSame(SlackAlert::FAILED, $slack->send('k1', 'A'));
        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'A'));
        $this->assertStringNotContainsString('hemligsokvag123', json_encode(array_map(fn ($m) => [$m->message, $m->context], $logged)));
    }

    public function test_connection_error_never_throws(): void
    {
        Http::fake([ProvisioningFakes::SLACK => fn () => throw new ConnectionException('cURL error 28 '.ProvisioningFakes::SLACK)]);

        $this->assertSame(SlackAlert::FAILED, app(SlackAlert::class)->send('k1', 'A'));
    }

    public function test_not_configured_sends_nothing(): void
    {
        config(['provisioning.slack_webhook_url' => '']);
        Http::fake();

        $this->assertSame(SlackAlert::NOT_CONFIGURED, app(SlackAlert::class)->send('k1', 'A'));
        Http::assertNothingSent();
    }

    public function test_escape(): void
    {
        $this->assertSame('&lt;!channel&gt; &amp; x', SlackAlert::escape('<!channel> & x'));
    }
}
