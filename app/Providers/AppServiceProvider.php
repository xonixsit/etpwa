<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The Plesk host's MySQL caps InnoDB index keys at 767 bytes; utf8mb4 makes the
        // default 255-char column 1020 bytes and migrate dies with SQLSTATE 1071 (R59).
        Schema::defaultStringLength(191);
    }
}
