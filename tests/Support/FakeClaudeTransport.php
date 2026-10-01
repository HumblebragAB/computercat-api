<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * PSR-18-klient som ersätter nätverket under den riktiga Anthropic-SDK:n.
 * SDK:n bygger och tolkar alltså på riktigt; bara HTTP är fejkat.
 */
final class FakeClaudeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    private ResponseInterface|Throwable|null $default = null;

    public function push(ResponseInterface|Throwable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function always(ResponseInterface|Throwable $response): self
    {
        $this->default = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? $this->default;
        if ($next === null) {
            throw new \LogicException('FakeClaudeTransport: inget svar i kön');
        }
        if ($next instanceof Throwable) {
            throw $next;
        }

        // Samma svar kan lämnas flera gånger (always); spola tillbaka kroppen.
        $next->getBody()->rewind();

        return $next;
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $request = end($this->requests);

        return json_decode((string) $request->getBody(), true);
    }

    public static function message(array $content, string $stopReason = 'end_turn', ?array $stopDetails = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_test'], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'stop_details' => $stopDetails,
            'usage' => ['input_tokens' => 1800, 'output_tokens' => 240],
        ]));
    }

    public static function json(array $data): Response
    {
        return self::message([['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]);
    }
}
