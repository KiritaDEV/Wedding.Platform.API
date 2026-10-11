<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_project_list_is_event_scoped_ordered_lightweight_and_authorized(): void
    {
        [$event, $owner] = $this->event();
        [$otherEvent] = $this->event();
        $later = $this->project($event, 'Later', now());
        $earlier = $this->project($event, 'Earlier', now()->subDay());
        $foreign = $this->project($otherEvent, 'Foreign', now()->subDays(2));
        $url = "/api/events/{$event->id}/websites";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $response = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.id', $earlier->id)
            ->assertJsonPath('data.0.eventId', $event->id)
            ->assertJsonPath('data.0.name', 'Earlier')
            ->assertJsonPath('data.0.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonPath('data.1.id', $later->id)
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->assertArrayNotHasKey('sections', $response->json('data.0'));
    }

    public function test_project_detail_is_scoped_to_the_route_event_and_preserves_template_metadata(): void
    {
        [$event, $owner] = $this->event();
        [$otherEvent] = $this->event();
        $project = $this->project($event, 'Main');
        $foreign = $this->project($otherEvent, 'Foreign');

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.eventId', $event->id)
            ->assertJsonPath('data.name', 'Main')
            ->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonPath('data.designSettings', $project->design_settings)
            ->assertJsonCount(3, 'data.sections');

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$foreign->id}")->assertNotFound();
    }

    public function test_project_aware_mutations_reuse_existing_behavior(): void
    {
        [$event, $owner] = $this->event();
        $project = $this->project($event, 'Main', templateKey: WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $base = "/api/events/{$event->id}/websites/{$project->id}";
        $hero = $project->sections()->where('type', 'hero')->sole();

        $design = ['colorTheme' => 'ink', 'fontSet' => 'fashion', 'artStyle' => 'frame', 'projectDefaults' => [], 'customColors' => []];
        $this->actingAs($owner)->putJson("{$base}/design", ['designSettings' => $design])
            ->assertOk()->assertJsonPath('data.designSettings', $design);

        $content = $hero->content;
        $content['compositions']['shared']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text'] = 'Project-aware';
        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}", ['content' => $content])
            ->assertOk();
        $this->assertSame($content, $hero->refresh()->content);

        $appearance = $hero->appearance;
        $appearance['shared']['height'] = ['unit' => 'svh', 'value' => 75];
        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", ['appearance' => $appearance])
            ->assertOk();
        $this->assertSame($appearance, $hero->refresh()->appearance);

        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/enabled", ['isEnabled' => false])
            ->assertOk();
        $this->assertFalse($hero->refresh()->is_enabled);

        $reversed = $project->sections()->pluck('id')->reverse()->values()->all();
        $this->actingAs($owner)->putJson("{$base}/sections/order", ['sectionIds' => $reversed])
            ->assertOk()->assertJsonPath('data.sections.0.id', $reversed[0]);
        $this->assertSame(range(10, $project->sections()->count() * 10, 10), $project->sections()->pluck('sort_order')->all());
    }

    public function test_project_and_event_section_isolation_returns_not_found_without_mutation(): void
    {
        [$event, $owner] = $this->event();
        [$otherEvent] = $this->event();
        $projectA = $this->project($event, 'A');
        $projectB = $this->project($event, 'B');
        $foreignProject = $this->project($otherEvent, 'Foreign');
        $sectionA = $projectA->sections()->where('type', 'hero')->sole();
        $foreignSection = $foreignProject->sections()->where('type', 'hero')->sole();
        $originalA = $sectionA->content;
        $originalForeign = $foreignSection->content;
        $payload = ['content' => ['headline' => 'Blocked', 'subheadline' => '']];
        $projectBBase = "/api/events/{$event->id}/websites/{$projectB->id}";

        $this->actingAs($owner)->putJson(
            "{$projectBBase}/sections/{$sectionA->id}",
            $payload,
        )->assertNotFound();
        $this->actingAs($owner)->putJson(
            "{$projectBBase}/sections/{$sectionA->id}/appearance",
            ['appearance' => WebsiteSectionAppearance::DEFAULT],
        )->assertNotFound();
        $this->actingAs($owner)->putJson(
            "{$projectBBase}/sections/{$sectionA->id}/enabled",
            ['isEnabled' => false],
        )->assertNotFound();
        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/websites/{$projectA->id}/sections/{$foreignSection->id}",
            $payload,
        )->assertNotFound();
        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/websites/{$foreignProject->id}/design",
            ['designSettings' => $projectA->design_settings],
        )->assertNotFound();
        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/websites/{$foreignProject->id}/sections/order",
            ['sectionIds' => $foreignProject->sections()->pluck('id')->all()],
        )->assertNotFound();

        $this->assertSame($originalA, $sectionA->refresh()->content);
        $this->assertSame($originalForeign, $foreignSection->refresh()->content);
    }

    public function test_legacy_singular_detail_and_mutation_remain_compatible(): void
    {
        [$event, $owner] = $this->event();
        $project = $this->project($event, 'Website');
        $hero = $project->sections()->where('type', 'hero')->sole();

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertOk()->assertJsonPath('data.id', $project->id)->assertJsonPath('data.name', 'Website');
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/enabled", [
            'isEnabled' => false,
        ])->assertOk()->assertJsonPath('data.id', $project->id);
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }

    private function project(
        Event $event,
        string $name,
        mixed $createdAt = null,
        string $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
    ): Website {
        $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
        $project = Website::factory()->for($event)->create(array_filter([
            'name' => $name,
            'template_key' => $templateKey,
            'design_settings' => app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($template),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], fn (mixed $value): bool => $value !== null));
        app(InitializeWebsiteSections::class)->handle($project);

        return $project->refresh();
    }
}
