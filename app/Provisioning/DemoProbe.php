<?php

namespace App\Provisioning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// GET mot en Demo-adress med basic auth. Bara adresser under Demo-domänen.
final class DemoProbe
{
    public static function status(string $url): int
    {
        $host = parse_url($url, PHP_URL_HOST);
        $suffix = '.'.config('provisioning.demo.domain_suffix');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || ! str_ends_with($host, $suffix)) {
            throw new \InvalidArgumentException("Inte en Demo-adress: {$url}");
        }

        try {
            return Http::withBasicAuth((string) config('provisioning.demo.basic_auth_user'), (string) config('provisioning.demo.basic_auth_password'))
                ->timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->get($url)
                ->status();
        } catch (ConnectionException) {
            return 0;
        }
    }
}
