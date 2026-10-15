<?php

namespace App\Services;

// Byggguiden (spec 2c, "Byggguiden"): versionerade byggregler och färdiga,
// testade klientfiler för spelrepon. Filerna ligger i resources/byggguide/<version>/
// och beskrivs av manifest.json med sha256 per fil. Sökvägar slås bara upp i
// manifestet; varje läsning kontrollerar sha256 och kastar vid avvikelse, så att
// det som serveras alltid är det som verifierades (computercat:byggguide:verify).
final class BuildGuide
{
    public const LATEST = 'v2';

    public const VERSION_PATTERN = '/^v[1-9][0-9]{0,2}\z/';

    // Ett MCP-svar ska hålla sig under Claude Codes 50 000 tecken även efter
    // JSON-escaping (code.claude.com/docs/en/mcp). Större filer hämtas via REST.
    public const INLINE_MAX_BYTES = 30000;

    // Spärrskriptet som Coolify hämtar och kör i bygget (spec delprojekt 3, "Coolify kör spärren").
    public const GATE_SCRIPT = 'scripts/cc-gate.mjs';

    // Coolify v4.4.6 sparar build_command i varchar(255) och godkänner bara
    // SHELL_SAFE_COMMAND_PATTERN (bilaga A, spike etapp B S10). Mönstret här är strängare.
    public const COOLIFY_COMMAND_MAX = 255;

    private const COOLIFY_SAFE = '/\A[A-Za-z0-9._\/=:@,+ -]+\z/';

    // sha256 av manifest.json för publicerade versioner. En publicerad version
    // ändras aldrig; en ändring blir en ny version. BuildGuideTest vaktar.
    public const FROZEN = [
        'v1' => '7f763d8ee8d01c0765debcc225e40a5884c979035f4e3649f00815325699dc85',
        'v2' => '777cdc1e42f16ca26a7daa4a2634f60cea9898d3345e27b062c0668be5e29503',
    ];

    // Bara låsta versioner syns (spec delprojekt 3, "Versioner"). En katalog under
    // arbete läses av hash och verify men visas och serveras aldrig.
    /** @return list<string> */
    public static function versions(): array
    {
        $versions = [];
        foreach (glob(self::base().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (self::isPublished(basename($dir))) {
                $versions[] = basename($dir);
            }
        }
        usort($versions, fn (string $a, string $b) => (int) substr($a, 1) <=> (int) substr($b, 1));

        return $versions;
    }

    public static function isPublished(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1
            && (self::FROZEN[$version] ?? '') !== ''
            && is_file(self::base()."/{$version}/manifest.json");
    }

    public static function root(string $version): string
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1 || ! is_file(self::base()."/{$version}/manifest.json")) {
            throw new BuildGuideNotFound('Unknown build guide version.');
        }

        return self::base().'/'.$version;
    }

    public static function manifest(?string $version = null): array
    {
        $root = self::root($version ?? self::LATEST);
        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== basename($root) || ! is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException('Byggguidens manifest för '.basename($root).' är ogiltigt.');
        }

        return $manifest;
    }

    /** @return array{path: string, sha256: string, bytes: int, content: string} */
    public static function file(string $version, string $path): array
    {
        foreach (self::manifest($version)['files'] as $entry) {
            if (($entry['path'] ?? null) === $path) {
                return [
                    'path' => $entry['path'],
                    'sha256' => $entry['sha256'],
                    'bytes' => $entry['bytes'],
                    'content' => self::read($version, $entry['source'], $entry['sha256']),
                ];
            }
        }

        throw new BuildGuideNotFound('Build guide file not found.');
    }

    public static function claudeMd(string $version): string
    {
        return self::file($version, 'CLAUDE.md')['content'];
    }

    // README-mallen med spelets namn i rubriken. Idé-blocket lämnas orört;
    // ReadmeIdea fyller det. Namnet får inte kunna skapa en markör eller HTML.
    public static function readme(string $version, string $name): string
    {
        $template = self::manifest($version)['server_templates']['readme'] ?? null;
        if (! is_array($template)) {
            throw new \RuntimeException("Byggguiden {$version} saknar README-mall.");
        }

        $text = self::read($version, $template['source'], $template['sha256']);
        $safe = str_replace(['<', '>'], ['&lt;', '&gt;'], trim((string) preg_replace('/\s+/u', ' ', $name)));

        return str_replace('{{name}}', $safe, $text);
    }

    // Det som MCP-verktygen och REST visar: inga interna sökvägar.
    public static function publicManifest(string $version): array
    {
        $m = self::manifest($version);

        return [
            'version' => $m['version'],
            'stack' => $m['stack'],
            'node' => $m['node'],
            'overrides' => $m['overrides'],
            'output' => $m['output'],
            'env' => $m['env'],
            'commands' => $m['commands'],
            'required_checks' => $m['required_checks'],
            'steps' => $m['steps'],
            'files' => array_map(fn (array $f) => [
                'path' => $f['path'],
                'sha256' => $f['sha256'],
                'bytes' => $f['bytes'],
                'delivery' => $f['bytes'] <= self::INLINE_MAX_BYTES ? 'mcp' : 'download',
                // Bara stora filer hämtas via REST. Dotfiler (.gitignore) går alltid via MCP,
                // eftersom Forges nginx nekar sökvägar med /. i produktion.
                'download_url' => $f['bytes'] <= self::INLINE_MAX_BYTES ? null : self::downloadUrl($m['version'], $f['path']),
            ], $m['files']),
        ];
    }

    // Har versionen spärrskriptet som Coolify kör i bygget? v1 har det inte. Okänd version: nej.
    public static function runsSecurityChecksInBuild(string $version): bool
    {
        try {
            return in_array(self::GATE_SCRIPT, array_column(self::manifest($version)['files'], 'path'), true);
        } catch (BuildGuideNotFound) {
            return false;
        }
    }

    // Kan spel på versionen byggas för iOS i släpprepot? Styrs av manifestets "ios"-block
    // (v3-kontraktet, plan 2026-10-15-slapp-testflight Task 4). Bara låsta versioner räknas.
    public static function supportsIos(string $version): bool
    {
        if (! self::isPublished($version)) {
            return false;
        }

        try {
            return self::iosBlockIsValid(self::manifest($version)['ios'] ?? null);
        } catch (BuildGuideNotFound) {
            return false;
        }
    }

    public static function iosBlockIsValid(mixed $ios): bool
    {
        return is_array($ios)
            && ($ios['project'] ?? null) === 'ios/App/App.xcodeproj'
            && ($ios['scheme'] ?? null) === 'App'
            && ($ios['package_manager'] ?? null) === 'spm'
            && is_string($ios['capacitor'] ?? null)
            && preg_match('/^\d+\.\d+\.\d+\z/', $ios['capacitor']) === 1;
    }

    // Varje versions CLAUDE.md börjar med raden "Byggguide: **vN**." (v1, v2; kontraktet för v3).
    public static function versionFromClaudeMd(string $text): ?string
    {
        return preg_match('/^Byggguide: \*\*(v[1-9][0-9]{0,2})\*\*\.(?:[ \t]|$)/m', $text, $m) === 1 ? $m[1] : null;
    }

    // Coolifys byggkommando för nya appar (spec delprojekt 3, "Coolify kör spärren", Eriks beslut
    // 2026-10-14). Ingen sha256 i kommandot (det ryms inte i 255 tecken): integriteten kommer av
    // att Computercat bara serverar låsta guidefiler, att BuildGuide::read kontrollerar varje fils
    // sha256 innan den skickas, och att hämtningen går över https till vår egen server. Spelrepot
    // kan inte ändra det som hämtas.
    public static function coolifyBuildCommand(string $version): ?string
    {
        if (! self::isPublished($version)) {
            throw new \RuntimeException("Byggguiden {$version} är inte publicerad; Coolify kan inte hämta dess skript.");
        }
        if (! self::runsSecurityChecksInBuild($version)) {
            return null;
        }

        $url = self::downloadUrl($version, self::GATE_SCRIPT);
        // Värden bakas in i appen i Coolify för gott och repareras aldrig. En lokal
        // APP_URL (http://localhost blir https://localhost) skulle fälla varje bygge.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || preg_match('/\.(test|localhost)$/', $host) === 1) {
            throw new \RuntimeException("Coolifys byggkommando kan inte hämta från {$host}; Coolify-servern når bara en publik värd. Kontrollera APP_URL.");
        }
        $command = 'npm run build && curl -fsS '.$url.' -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs';
        // Fail loud här i stället för 500 och ingen app i Coolify.
        if (strlen($command) > self::COOLIFY_COMMAND_MAX) {
            throw new \RuntimeException('Coolifys byggkommando blir '.strlen($command).' tecken; Coolify sparar högst '.self::COOLIFY_COMMAND_MAX.'. Kontrollera APP_URL.');
        }
        if (preg_match(self::COOLIFY_SAFE, str_replace(' && ', ' ', $command)) !== 1) {
            throw new \RuntimeException('Coolifys byggkommando innehåller tecken som Coolify inte godkänner. Kontrollera APP_URL.');
        }

        return $command;
    }

    // Från APP_URL och alltid https: url() kan ge http:// bakom proxyn, och
    // spelets Claude hämtar med curl -fsS utan -L.
    public static function downloadUrl(string $version, string $path): string
    {
        $base = rtrim((string) preg_replace('#^http://#', 'https://', (string) config('app.url')), '/');
        $parts = parse_url($base);
        if ($base === '' || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === '') {
            throw new \RuntimeException('APP_URL måste vara en http(s)-adress med värd; byggguidens nedladdningar serveras alltid över https.');
        }

        return $base.'/api/v1/build-guide/'.$version.'/files/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function read(string $version, string $source, string $sha256): string
    {
        $file = self::root($version).'/'.$source;
        // source kommer ur manifestet, aldrig från anroparen; kontrollen är ett extra skydd.
        if (str_contains($source, '..') || ! is_file($file)) {
            throw new \RuntimeException("Byggguidens fil {$source} saknas i {$version}.");
        }
        // En länk (eller en länkad katalog på vägen) kan peka var som helst på servern.
        $real = realpath($file);
        $rootReal = realpath(self::root($version));
        if (is_link($file) || $real === false || $rootReal === false || ! str_starts_with($real, $rootReal.'/')) {
            throw new \RuntimeException("Byggguidens fil {$source} i {$version} är en symbolisk länk eller ligger utanför guiden.");
        }

        $content = (string) file_get_contents($file);
        if (! hash_equals($sha256, hash('sha256', $content))) {
            throw new \RuntimeException("Byggguidens fil {$source} i {$version} stämmer inte med manifestets sha256.");
        }

        return $content;
    }

    private static function base(): string
    {
        // Tester pekar om till en temporär kopia; i drift alltid resources/byggguide.
        return rtrim((string) (config('byggguide.path') ?: resource_path('byggguide')), '/');
    }
}
