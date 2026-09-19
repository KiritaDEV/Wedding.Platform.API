<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_plural_post_creates_first_second_and_third_independent_projects(): void
    {
        [$event, $owner] = $this->event();
        $url = "/api/events/{$event->id}/websites";

        $first = $this->actingAs($owner)->postJson($url, [
            'name' => '  Ceremony Site  ',
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated()
            ->assertJsonPath('data.eventId', $event->id)
            ->assertJsonPath('data.name', 'Ceremony Site')
            ->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonCount(3, 'data.sections');

        $second = $this->actingAs($owner)->postJson($url, [
            'name' => 'Reception Site',
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertCreated()->assertJsonCount(3, 'data.sections');

        $third = $this->actingAs($owner)->postJson($url, [
            'name' => 'Reception Site',
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated();

        $ids = [$first->json('data.id'), $second->json('data.id'), $third->json('data.id')];
        $this->assertCount(3, array_unique($ids));
        $this->assertTrue(collect($ids)->every(fn (string $id): bool => Str::isUlid($id)));
        $this->assertSame(3, $event->websiteProjects()->count());
        $this->assertSame([$event->id], $event->websiteProjects()->pluck('event_id')->unique()->values()->all());
        $this->assertSame(2, $event->websiteProjects()->where('name', 'Reception Site')->count());
    }

    public function test_project_name_and_template_validation_are_enforced(): void
    {
        [$event, $owner] = $this->event();
        $url = "/api/events/{$event->id}/websites";
        $validTemplate = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1;

        $this->actingAs($owner)->postJson($url, [
            'name' => str_repeat('A', Website::MAX_NAME_LENGTH),
            'templateKey' => $validTemplate,
        ])->assertCreated()->assertJsonPath('data.name', str_repeat('A', Website::MAX_NAME_LENGTH));

        $this->actingAs($owner)->postJson($url, [
            'name' => str_repeat('A', Website::MAX_NAME_LENGTH + 1),
            'templateKey' => $validTemplate,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->postJson($url, [
            'name' => '   ',
            'templateKey' => $validTemplate,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->postJson($url, [
            'name' => 'Unknown',
            'templateKey' => 'unknown-template',
        ])->assertUnprocessable()->assertJsonValidationErrors('templateKey');

        $this->assertSame(1, $event->websiteProjects()->count());
    }

    public function test_project_creation_requires_event_access(): void
    {
        [$event, $owner] = $this->event();
        $unrelated = User::factory()->create();
        $url = "/api/events/{$event->id}/websites";
        $payload = ['name' => 'Private', 'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1];

        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs($unrelated)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->postJson($url, $payload)->assertCreated();

        $this->assertSame(1, $event->websiteProjects()->count());
    }

    public function test_new_project_uses_defaults_without_copying_project_or_event_owned_data(): void
    {
        [$event, $owner] = $this->event();
        $url = "/api/events/{$event->id}/websites";
        $this->actingAs($owner)->postJson($url, [
            'name' => 'A',
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated();
        $projectA = $event->websiteProjects()->sole();
        $heroA = $projectA->sections()->where('type', 'hero')->sole();
        $heroA->update(['content' => ['headline' => 'Project A', 'subheadline' => 'Keep me']]);
        $designA = ['colorTheme' => 'olive', 'fontSet' => 'romantic', 'artStyle' => 'botanical'];
        $projectA->update(['design_settings' => $designA]);
        $sectionSnapshot = $projectA->sections()->get()->map->only(['id', 'content', 'appearance', 'sort_order', 'is_enabled'])->all();
        MediaAsset::query()->create([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'created_by_user_id' => $owner->id,
            'original_filename' => 'existing.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 100,
            'content_hash' => hash('sha256', 'existing'),
            'storage_disk' => 'public',
            'original_path' => "events/{$event->id}/media/existing/original.jpg",
        ]);

        $template = app(WebsiteTemplateRegistry::class)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $response = $this->actingAs($owner)->postJson($url, [
            'name' => 'B',
            'templateKey' => $template->key,
        ])->assertCreated()->assertJsonPath(
            'data.designSettings',
            app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($template),
        );
        $projectB = Website::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['colorTheme', 'fontSet', 'artStyle', 'projectDefaults', 'customColors'], array_keys($projectB->design_settings));
        $this->assertSame([], $projectB->design_settings['projectDefaults']);
        $this->assertStringContainsString(
            '"projectDefaults":{}',
            DB::table('websites')->where('id', $projectB->id)->value('design_settings'),
        );
        $this->assertSame(3, $projectB->sections()->count());
        $this->assertEmpty(array_intersect(
            $projectA->sections()->pluck('id')->all(),
            $projectB->sections()->pluck('id')->all(),
        ));
        $this->assertSame($designA, $projectA->refresh()->design_settings);
        $this->assertSame($sectionSnapshot, $projectA->sections()->get()->map->only(['id', 'content', 'appearance', 'sort_order', 'is_enabled'])->all());
        $this->assertSame(1, $event->mediaAssets()->count());
        $this->assertSame($event->id, $event->mediaAssets()->sole()->event_id);
    }

    public function test_legacy_routes_conflict_when_multiple_projects_exist_without_mutating_either(): void
    {
        [$event, $owner] = $this->event();
        $plural = "/api/events/{$event->id}/websites";
        foreach (['A', 'B'] as $name) {
            $this->actingAs($owner)->postJson($plural, [
                'name' => $name,
                'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            ])->assertCreated();
        }
        $projects = $event->websiteProjects()->with('sections')->orderBy('id')->get();
        $hero = $projects[0]->sections->firstWhere('type', 'hero');
        $before = $projects->map(fn (Website $website) => [
            'id' => $website->id,
            'template_key' => $website->template_key,
            'design_settings' => $website->design_settings,
            'sections' => $website->sections->map->only(['id', 'content', 'appearance', 'sort_order', 'is_enabled'])->all(),
        ])->all();
        $message = 'This Event has multiple Website Projects. Use a project-specific route.';

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertConflict()->assertJsonPath('message', $message);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website/templates")
            ->assertNotFound();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/enabled", [
            'isEnabled' => false,
        ])->assertConflict()->assertJsonPath('message', $message);
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/website", [
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertConflict();

        $after = $event->websiteProjects()->with('sections')->orderBy('id')->get()->map(fn (Website $website) => [
            'id' => $website->id,
            'template_key' => $website->template_key,
            'design_settings' => $website->design_settings,
            'sections' => $website->sections->map->only(['id', 'content', 'appearance', 'sort_order', 'is_enabled'])->all(),
        ])->all();
        $this->assertSame($before, $after);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$projects[0]->id}")
            ->assertOk()->assertJsonPath('data.id', $projects[0]->id);
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }
}
