<?php

declare(strict_types=1);

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Install\InstallServiceProvider;

return [
    /*
     | First, and it has to be. It runs InstallerEnvironment::prepare() in its register()
     | phase, which is what gives an unconfigured Planvio an application key and a
     | database-free session before anything else in the framework asks for either.
     */
    InstallServiceProvider::class,
    AppServiceProvider::class,
    AiServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    AdminPanelProvider::class,
];
