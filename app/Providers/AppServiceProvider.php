<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind domain services here as modules are implemented.
    }

    public function boot(): void
    {
        // Application-wide bootstrapping belongs here.
    }
}
