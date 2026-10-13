<?php

namespace Tests\Unit;

use App\Support\ThemeAsset;
use Tests\TestCase;

class ThemeAssetTest extends TestCase
{
    public function test_versionerar_med_filemtime_nar_filen_finns(): void
    {
        $dir = sys_get_temp_dir().'/cc-theme-'.uniqid();
        mkdir($dir.'/css', 0777, true);
        file_put_contents($dir.'/css/x.css', 'a{}');
        touch($dir.'/css/x.css', 1700000000);
        $this->app->usePublicPath($dir);

        $this->assertSame(asset('css/x.css').'?v=1700000000', ThemeAsset::url('css/x.css'));
    }

    public function test_saknad_fil_ger_url_utan_version_och_utan_varning(): void
    {
        $this->app->usePublicPath(sys_get_temp_dir().'/cc-theme-tom-'.uniqid());

        $this->assertSame(asset('css/x.css'), ThemeAsset::url('css/x.css'));
    }
}
