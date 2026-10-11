<?php

namespace Tests;

use App\Actions\Websites\InitializeEventWebsite;
use App\Models\Event;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Existing mutation regressions act on a freshly loaded draft; conflict tests supply stale tokens explicitly. */
    protected function draftMutation(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        if (preg_match('#^/api/events/([^/]+)/(website|websites/([^/]+))(?:/|$)#', $uri, $matches) === 1) {
            $query = Website::query()->where('event_id', $matches[1]);
            if (! empty($matches[3])) {
                $query->whereKey($matches[3]);
            }
            $revision = $query->value('draft_revision');
            if ($revision !== null) {
                $headers += ['X-Website-Draft-Revision' => $revision];
            }
        }

        return $this->json($method, $uri, $data, $headers);
    }

    protected function draftPutJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->draftMutation('PUT', $uri, $data, $headers);
    }

    protected function draftPostJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->draftMutation('POST', $uri, $data, $headers);
    }

    protected function draftDeleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->draftMutation('DELETE', $uri, $data, $headers);
    }

    protected function initializeWebsite(Event $event, string $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1): Website
    {
        return app(InitializeEventWebsite::class)->handle($event, $templateKey);
    }
}
