<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteTemplateSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_creation_catalog_is_stable_for_zero_one_and_multiple_projects(): void
    {
        [$event, $owner] = $this->createEvent();
        $url = "/api/events/{$event->id}/website-templates";

        foreach ([0, 1, 2] as $projectCount) {
            $response = $this->actingAs($owner)->getJson($url)
                ->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.key', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
                ->assertJsonPath('data.0.displayName', 'Classic Filipiniana')
                ->assertJsonPath('data.0.styleTags.0', 'Classic')
                ->assertJsonPath('data.1.key', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)
                ->assertJsonPath('data.1.displayName', 'Modern Editorial')
                ->assertJsonPath('data.1.styleTags', ['Modern', 'Editorial', 'Minimal'])
                ->assertJsonMissingPath('data.0.isSelected')
                ->assertJsonMissingPath('data.0.designOptions');

            $this->assertNotEmpty($response->json('data.0.description'));

            if ($projectCount < 2) {
                app(CreateWebsiteProject::class)->handle(
                    $event,
                    'Website '.($projectCount + 1),
                    $projectCount === 0 ? WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1 : WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
                );
            }
        }
    }

    public function test_creation_catalog_uses_event_authorization(): void
    {
        [$event, $owner] = $this->createEvent();
        [, $otherOwner] = $this->createEvent();
        $url = "/api/events/{$event->id}/website-templates";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($otherOwner)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk();
    }

    public function test_every_catalog_template_can_create_a_project_with_the_same_compatibility_semantics(): void
    {
        [$event, $owner] = $this->createEvent();
        $templates = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website-templates")
            ->assertOk()->json('data');

        foreach ($templates as $index => $template) {
            $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites", [
                'name' => 'Project '.($index + 1),
                'templateKey' => $template['key'],
            ])->assertCreated()->assertJsonPath('data.templateKey', $template['key']);
        }
    }

    public function test_persisted_template_is_immutable_while_ordinary_updates_still_work(): void
    {
        [$event] = $this->createEvent();
        $website = Website::factory()->for($event)->create([
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ]);

        $website->update(['name' => 'Renamed Website']);
        $this->assertSame('Renamed Website', $website->refresh()->name);

        try {
            $website->update(['template_key' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1]);
            $this->fail('Changing a persisted Website Project template should fail.');
        } catch (DomainException $exception) {
            $this->assertSame('A Website Project template cannot be changed after creation.', $exception->getMessage());
        }

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->refresh()->template_key);
    }

    public function test_classic_and_modern_can_both_be_selected_during_creation(): void
    {
        [$event] = $this->createEvent();

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $key) {
            $project = app(CreateWebsiteProject::class)->handle($event, $key, $key);
            $this->assertSame($key, $project->template_key);
        }
    }

    public function test_former_template_switch_and_project_catalog_routes_are_unavailable(): void
    {
        [$event, $owner] = $this->createEvent();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $payload = ['templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", $payload)->assertNotFound();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/template", $payload)->assertNotFound();
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}/templates")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website/templates")->assertNotFound();
    }

    /** @return array{Event, User} */
    private function createEvent(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }
}
