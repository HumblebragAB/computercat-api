<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

// Passports authorize-sida skickar oinloggade hit. Filament äger inloggningen.
// Route::redirect och inte redirect()->guest(): guest() skriver över
// url.intended (authorize-adressen) med /login, och då kommer man aldrig tillbaka.
Route::redirect('/login', '/admin/login')->name('login');
