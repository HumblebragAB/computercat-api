<?php

namespace App\Support;

/**
 * URL till en statisk temafil i public/ med cache-bust. Produktionen bygger inga
 * Vite-assets, så temat ligger som vanliga filer. En saknad fil ger en URL utan
 * version i stället för en varning (testerna byter public_path till en tom katalog).
 */
class ThemeAsset
{
    public static function url(string $path): string
    {
        $file = public_path($path);

        return is_file($file) ? asset($path).'?v='.filemtime($file) : asset($path);
    }
}
