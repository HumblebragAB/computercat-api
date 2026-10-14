<?php

namespace App\Console\Commands;

use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

// Skriver files[] och server_templates i manifest.json utifrån filerna på disk.
// Bara för en version som inte är publicerad: en publicerad version ändras aldrig.
class BuildGuideHash extends Command
{
    protected $signature = 'computercat:byggguide:hash {version : Byggguidens version, t.ex. v2}';

    protected $description = 'Skriver sha256 och bytes för byggguidens filer i manifest.json (bara opublicerade versioner)';

    public function handle(): int
    {
        $version = (string) $this->argument('version');

        if ((BuildGuide::FROZEN[$version] ?? '') !== '') {
            $this->error("{$version} är publicerad och ändras aldrig. Skapa en ny version.");

            return self::FAILURE;
        }

        try {
            $root = BuildGuide::root($version);
        } catch (BuildGuideNotFound $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $readme = $root.'/templates/README.md';
        if (! is_file($readme)) {
            $this->error("{$version} saknar templates/README.md.");

            return self::FAILURE;
        }

        // Symboliska länkar (filer eller kataloger) kan peka utanför guiden; de hashas aldrig.
        foreach ((new Finder)->in($root.'/files')->ignoreDotFiles(false)->ignoreVCS(false) as $entry) {
            if ($entry->isLink()) {
                $this->error('files/'.str_replace('\\', '/', $entry->getRelativePathname()).' är en symbolisk länk. Ersätt den med en vanlig fil och kör igen.');

                return self::FAILURE;
            }
        }
        if (is_link($readme)) {
            $this->error('templates/README.md är en symbolisk länk.');

            return self::FAILURE;
        }

        $files = [];
        foreach ((new Finder)->files()->in($root.'/files')->ignoreDotFiles(false)->ignoreVCS(false) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            // Dotfiler lagras som dot.<namn>; en riktig dotfil (t.ex. .DS_Store) är ett misstag.
            foreach (explode('/', $relative) as $segment) {
                if (str_starts_with($segment, '.')) {
                    $this->error("files/{$relative}: dotfiler lagras som dot.<namn>. Ta bort eller byt namn och kör igen.");

                    return self::FAILURE;
                }
            }
            $files[] = [
                'path' => self::targetPath($relative),
                'source' => 'files/'.$relative,
                'sha256' => hash_file('sha256', $file->getPathname()),
                'bytes' => $file->getSize(),
            ];
        }
        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $manifest['server_templates'] = ['readme' => ['source' => 'templates/README.md', 'sha256' => hash_file('sha256', $readme), 'bytes' => filesize($readme)]];
        $manifest['files'] = $files;

        file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->info(count($files)." filer i {$version}.");

        return self::SUCCESS;
    }

    // Dotfiler lagras som dot.<namn> så att computercat-api:s git inte läser dem.
    private static function targetPath(string $relative): string
    {
        return implode('/', array_map(
            fn (string $segment) => str_starts_with($segment, 'dot.') ? '.'.substr($segment, 4) : $segment,
            explode('/', $relative),
        ));
    }
}
