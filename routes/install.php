<?php

declare(strict_types=1);

use App\Http\Controllers\Install\FinishController;
use App\Http\Controllers\Install\LanguageController;
use App\Http\Controllers\Install\RequirementsController;
use App\Http\Controllers\Install\WelcomeController;
use App\Livewire\Installer\Administrator;
use App\Livewire\Installer\Ai;
use App\Livewire\Installer\Application;
use App\Livewire\Installer\Database;
use App\Livewire\Installer\Email;
use App\Livewire\Installer\Install;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The installation wizard
|--------------------------------------------------------------------------
|
| Loaded from routes/web.php, so every route here sits inside the `web` group and gets the
| session, the CSRF check and Livewire's endpoint. `EnsureInstalled` — appended globally in
| bootstrap/app.php — exempts `install` and `install/*` so these answer while the application
| is still unconfigured.
|
| `not-installed` is the gate in the other direction, and it is on the group rather than on
| individual routes: an installer route somebody forgot to guard is a way for a visitor to
| repoint a live installation at a database of their own (ARCHITECTURE.md §9, spec §74).
| The check reads the lock file, so hiding the route is not what protects it.
|
| `finish` is deliberately outside that group. It is the one screen that has to render after
| the lock exists — it is where the installation reports success — and it guards itself on
| the completed checkpoint instead.
|
| Welcome, Requirements and Finish are plain controllers rather than Livewire components on
| purpose: they need no session and no round trip, so they still render on a server where
| `storage/` is not writable, which is precisely the failure the requirements screen exists
| to explain.
|
*/

Route::prefix('install')->name('install.')->group(function (): void {

    Route::middleware('not-installed')->group(function (): void {
        Route::get('/', [WelcomeController::class, 'show'])->name('welcome');
        Route::post('restart', [WelcomeController::class, 'restart'])->name('restart');

        // The language of the wizard itself, offered on every step by the installer shell.
        Route::post('language', LanguageController::class)->name('language');

        Route::get('requirements', [RequirementsController::class, 'show'])->name('requirements');
        Route::post('requirements', [RequirementsController::class, 'proceed'])->name('requirements.continue');

        Route::get('database', Database::class)->name('database');
        Route::get('application', Application::class)->name('application');
        Route::get('administrator', Administrator::class)->name('administrator');
        Route::get('email', Email::class)->name('email');
        Route::get('ai', Ai::class)->name('ai');
        Route::get('run', Install::class)->name('install');
    });

    Route::get('finish', [FinishController::class, 'show'])->name('finish');
});
