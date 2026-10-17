<?php

namespace Tests\Unit\Matches;

use App\Matches\IllegalMove;
use App\Matches\MatchContext;
use LogicException;
use Tests\TestCase;

class MatchContextTest extends TestCase
{
    private function ctx(array $hidden = []): MatchContext
    {
        return new MatchContext(new FakeRules, ['rounds' => 3], ['log' => []], $hidden);
    }

    public function test_emit_applies_event_and_records_it(): void
    {
        $ctx = $this->ctx();
        $ctx->emit('picked', 1, ['n' => 5]);

        $this->assertSame([['picked', 1]], $ctx->state()['log']);
        $this->assertSame([['type' => 'picked', 'seat' => 1, 'payload' => ['n' => 5]]], $ctx->emitted());
    }

    public function test_options_and_hidden_are_exposed(): void
    {
        $ctx = $this->ctx([1 => ['x' => 1]]);

        $this->assertSame(['rounds' => 3], $ctx->options());
        $this->assertSame(['x' => 1], $ctx->hidden(1));
        $this->assertNull($ctx->hidden(2));
    }

    public function test_set_hidden_shows_in_changes_and_hidden(): void
    {
        $ctx = $this->ctx();
        $ctx->setHidden(2, ['n' => 9]);
        $ctx->setHidden(1, null);

        $this->assertSame(['n' => 9], $ctx->hidden(2));
        $this->assertSame([2 => ['n' => 9], 1 => null], $ctx->hiddenChanges());
    }

    public function test_no_changes_when_untouched(): void
    {
        $this->assertSame([], $this->ctx([1 => ['a' => 1]])->hiddenChanges());
    }

    public function test_finish(): void
    {
        $ctx = $this->ctx();
        $this->assertFalse($ctx->isFinished());
        $ctx->finish();
        $this->assertTrue($ctx->isFinished());
    }

    public function test_reserved_types_cannot_be_emitted(): void
    {
        $this->assertSame(['left', 'expired', 'rematch_requested', 'rematch'], MatchContext::RESERVED_TYPES);

        foreach (MatchContext::RESERVED_TYPES as $type) {
            $ctx = $this->ctx();
            try {
                $ctx->emit($type, null);
                $this->fail("emit($type) should throw");
            } catch (LogicException) {
                $this->assertSame([], $ctx->emitted());
            }
        }
    }

    public function test_illegal_move_carries_error_code(): void
    {
        $e = new IllegalMove('not_your_turn', 'Inte din tur');

        $this->assertSame('not_your_turn', $e->errorCode);
        $this->assertSame('Inte din tur', $e->getMessage());
    }
}
