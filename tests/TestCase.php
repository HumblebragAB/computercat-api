<?php

namespace Tests;

use App\Provisioning\Http\Redactor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> DELETE-anrop mot api.github.com under testet. */
    public static array $githubDeletes = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$githubDeletes = [];

        // Inget test får nå nätet; ett ofejkat anrop kastar.
        Http::preventStrayRequests();

        // Spec 2c: koden skickar aldrig DELETE till GitHub. Vakten ligger före
        // fejken och gäller hela sviten. Anropet registreras också statiskt,
        // eftersom sagan fångar alla undantag och annars kunde dölja det.
        Http::globalRequestMiddleware(function (RequestInterface $request) {
            if (strtoupper($request->getMethod()) === 'DELETE' && strtolower($request->getUri()->getHost()) === 'api.github.com') {
                self::$githubDeletes[] = (string) $request->getUri();

                throw new \LogicException('DELETE mot api.github.com är förbjudet.');
            }

            return $request;
        });
    }

    protected function tearDown(): void
    {
        $deletes = self::$githubDeletes;

        // Utfärdade tokens registreras statiskt i Redactor; nollställs så att
        // inget test ärver ett annat tests hemligheter.
        (new \ReflectionProperty(Redactor::class, 'runtimeSecrets'))->setValue(null, []);

        parent::tearDown();

        $this->assertSame([], $deletes, 'Koden skickade DELETE till api.github.com: '.implode(', ', $deletes));
    }
}
