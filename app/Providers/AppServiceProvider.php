<?php

namespace App\Providers;

use App\Website\Publishing\SchedulePublicationRule;
use App\Website\Publishing\WebsitePublicationCoordinator;
use App\Website\Publishing\WebsitePublicationRule;
use App\Website\ScheduleMediaReferenceProvider;
use App\Website\WebsiteMediaReferenceProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WebsitePublicationCoordinator::class);
        $this->app->tag([ScheduleMediaReferenceProvider::class], WebsiteMediaReferenceProvider::class);
        $this->app->tag([SchedulePublicationRule::class], WebsitePublicationRule::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
