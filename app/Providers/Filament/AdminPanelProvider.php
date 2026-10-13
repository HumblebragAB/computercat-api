<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Support\ThemeAsset;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** Neutral varm gray-skala för System 1 (ljust läge). 50 = paper, 200 = desk, 950 = ink.
     *  400–600 används som dämpad text och platshållare och klarar 4.5:1 mot vitt och paper. */
    public const GRAY = [
        50 => '#fbfaf6',
        100 => '#f3f1ea',
        200 => '#e9e6df',
        300 => '#cfcac0',
        400 => '#736f66',
        500 => '#6b675e',
        600 => '#55524b',
        700 => '#3d3a35',
        800 => '#2a2825',
        900 => '#1c1b19',
        950 => '#111111',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Computer Cat')
            // 1-bit: alla semantiska färger är bläck. Betydelse visas med form (fylld, ram, ⚠), se cc-admin.css.
            ->colors([
                'primary' => Color::hex('#111111'),
                'gray' => self::GRAY,
                'danger' => Color::hex('#111111'),
                'warning' => Color::hex('#111111'),
                'success' => Color::hex('#111111'),
                'info' => Color::hex('#111111'),
            ])
            ->font('IBM Plex Mono', ThemeAsset::url('css/cc-theme.css'), LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->darkMode(false)
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.e(ThemeAsset::url('css/cc-admin.css')).'">'
            )
            // Login-filerna bara på login, i <head> så att kortet inte blinkar ostylat.
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.e(ThemeAsset::url('css/cc-login.css')).'">'
                    .'<script src="'.e(ThemeAsset::url('js/cc-login.js')).'" defer></script>',
                scopes: Login::class,
            )
            ->renderHook(
                'panels::body.end',
                fn () => '<script src="'.e(ThemeAsset::url('js/cc-eggs.js')).'"></script>'
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
