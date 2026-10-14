<?php

namespace Tests\Unit;

use App\Rules\GameSlug;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameSlugTest extends TestCase
{
    public static function valid(): array
    {
        return [['abc'], ['kattsvep'], ['a-b'], ['a1b2'], ['tocco'], ['glosis'], ['tells'], [str_repeat('a', 40)], ['e2e-spike']];
    }

    public static function invalid(): array
    {
        return [['ab'], [str_repeat('a', 41)], ['Abc'], ['1abc'], ['-abc'], ['abc-'], ['a--b'], ['a_b'], ['a.b'], ["abc\n"], ['åäö'], [''], [123], [null]];
    }

    #[DataProvider('valid')]
    public function test_valid(string $slug): void
    {
        $this->assertTrue(GameSlug::isValid($slug), $slug);
        $this->assertTrue(Validator::make(['slug' => $slug], ['slug' => [new GameSlug]])->passes(), $slug);
    }

    #[DataProvider('invalid')]
    public function test_invalid(mixed $slug): void
    {
        $this->assertFalse(GameSlug::isValid($slug));
        // required: Laravel hoppar över icke-implicita regler för tom sträng.
        $this->assertTrue(Validator::make(['slug' => $slug], ['slug' => ['required', new GameSlug]])->fails());
    }

    public function test_reserved_names_are_rejected(): void
    {
        config(['provisioning.reserved_slugs' => ['www', 'api', 'ordjakt']]);

        $this->assertTrue(Validator::make(['slug' => 'api'], ['slug' => [new GameSlug]])->fails());
        $this->assertTrue(Validator::make(['slug' => 'ordjakt'], ['slug' => [new GameSlug]])->fails());
        $this->assertTrue(Validator::make(['slug' => 'kattsvep'], ['slug' => [new GameSlug]])->passes());
    }
}
