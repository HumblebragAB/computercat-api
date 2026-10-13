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
    /** Varm gray-skala för Fosfor-temat. 950/900 = --cc-bg/--cc-surface, 500 = --cc-muted. */
    public const GRAY = [
        50 => '#faf6ee',
        100 => '#f2ead9',
        200 => '#e2d5bb',
        300 => '#c9b48c',
        400 => '#b39a6a',
        500 => '#a0824a',
        600 => '#6b5733',
        700 => '#4a3c23',
        800 => '#2e2412',
        900 => '#15110a',
        950 => '#0d0b08',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Computer Cat')
            ->colors([
                'primary' => Color::hex('#f2b544'),
                'gray' => self::GRAY,
                'danger' => Color::hex('#e8664a'),
                'warning' => Color::hex('#f08a24'),
                'success' => Color::hex('#9fd36b'),
                'info' => Color::hex('#8fb3a8'),
            ])
            ->font('IBM Plex Mono', ThemeAsset::url('css/cc-theme.css'), LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->darkMode(true, true)
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
