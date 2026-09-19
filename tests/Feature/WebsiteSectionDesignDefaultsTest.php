<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSectionDesignDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_hero_rejects_obsolete_section_design_defaults(): void
    {
        [$event, $owner, $project] = $this->project(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $url = $this->defaultsUrl($event, $project, $hero->id);

        $this->actingAs($owner)->putJson($url, ['designDefaults' => ['headingColorId' => 'ink-accent']])->assertUnprocessable();
        $this->assertNull($hero->refresh()->design_defaults);
    }

    /** @return array{Event, User, Website} */
    private function project(string $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
        $project = Website::factory()->for($event)->create([
            'template_key' => $templateKey,
            'design_settings' => app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($template),
        ]);
        app(InitializeWebsiteSections::class)->handle($project);

        return [$event, $owner, $project->refresh()];
    }

    private function base(Event $event, Website $project): string
    {
        return "/api/events/{$event->id}/websites/{$project->id}";
    }

    private function defaultsUrl(Event $event, Website $project, string $sectionId): string
    {
        return $this->base($event, $project)."/sections/{$sectionId}/design-defaults";
    }

    /** @param list<array<string, mixed>> $sections */
    private function sectionIndex(array $sections, string $type): int
    {
        return array_search($type, array_column($sections, 'type'), true);
    }
}
