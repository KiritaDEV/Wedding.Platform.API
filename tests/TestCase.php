<?php

namespace Tests;

use App\Actions\Websites\InitializeEventWebsite;
use App\Models\Event;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function initializeWebsite(Event $event, string $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1): Website
    {
        return app(InitializeEventWebsite::class)->handle($event, $templateKey);
    }
}
