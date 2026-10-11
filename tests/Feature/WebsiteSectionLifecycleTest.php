<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Models\Event;
use App\Models\User;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_repeatable_blank_lifecycle_uses_stable_ids_names_and_dense_id_based_order(): void
    {
        [$event, $owner] = $this->event();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";

        $firstResponse = $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated();
        $first = collect($firstResponse->json('data.sections'))->firstWhere('type', 'blank');
        $this->assertSame('Section', $first['displayName']);
        $this->assertSame('Section 1', $first['editorName']);
        $this->assertSame(['elements' => [], 'order' => []], $first['content']['compositions']['shared']['childFlow']);

        $secondResponse = $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated();
        $blanks = collect($secondResponse->json('data.sections'))->where('type', 'blank')->values();
        $this->assertCount(2, $blanks);
        $this->assertSame(['Section 1', 'Section 2'], $blanks->pluck('editorName')->all());
        $secondId = $blanks[1]['id'];

        $this->actingAs($owner)->putJson("{$base}/{$first['id']}/editor-name", ['editorName' => '  Travel   notes  '])
            ->assertOk()->assertJsonPath('data.sections.3.editorName', 'Travel notes');
        $this->actingAs($owner)->putJson("{$base}/{$secondId}/editor-name", ['editorName' => 'Travel notes'])->assertOk();
        $this->assertSame($first['id'], $event->website->sections()->findOrFail($first['id'])->id);

        $ids = $event->website->sections()->pluck('id')->all();
        $reversed = array_reverse($ids);
        $this->actingAs($owner)->putJson("{$base}/order", ['sectionIds' => $reversed])->assertOk();
        $this->assertSame($reversed, $event->website->sections()->pluck('id')->all());
        $this->actingAs($owner)->putJson("{$base}/order", ['sectionIds' => array_slice($reversed, 1)])->assertUnprocessable();

        $this->actingAs($owner)->putJson("{$base}/{$first['id']}/enabled", ['isEnabled' => false])->assertOk();
        $this->actingAs($owner)->putJson("{$base}/{$first['id']}/enabled", ['isEnabled' => true])->assertOk();
        $this->assertSame($first['id'], $event->website->sections()->findOrFail($first['id'])->id);

        $this->actingAs($owner)->deleteJson("{$base}/{$secondId}")->assertOk();
        $this->assertDatabaseMissing('website_sections', ['id' => $secondId]);
        $remaining = $event->website->sections()->count();
        $this->assertSame(range(10, $remaining * 10, 10), $event->website->sections()->pluck('sort_order')->all());
    }

    public function test_duplicate_regenerates_every_owned_identity_and_preserves_media_references_and_authored_state(): void
    {
        [$event, $owner] = $this->event();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";
        $payload = $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated()->json('data.sections');
        $sourcePayload = collect($payload)->firstWhere('type', 'blank');
        $source = $event->website->sections()->findOrFail($sourcePayload['id']);
        $mediaId = (string) Str::ulid();
        $source->update([
            'is_enabled' => false,
            'content' => ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [[
                'id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'backgroundMedia' => ['assetId' => $mediaId, 'focalPoint' => ['x' => .2, 'y' => .8], 'zoom' => 1.6, 'responsive' => ['tablet' => ['assetId' => $mediaId, 'zoom' => .7], 'mobile' => ['assetId' => $mediaId, 'focalPoint' => ['x' => .8, 'y' => .3], 'zoom' => .4]]], 'appearance' => ['backgroundImageOpacity' => 35], 'layout' => ['direction' => 'horizontal', 'division' => '60-40', 'gap' => 'm'], 'children' => [[
                    'id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [[
                        'id' => 'item', 'type' => 'image', 'mediaId' => $mediaId, 'alt' => 'Photo',
                    ]],
                ]],
            ]], 'order' => [['kind' => 'element', 'id' => 'group']]]], 'custom' => ['mobile' => ['childFlow' => [
                'elements' => [['id' => 'mobile-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Mobile']]]]]]],
                'order' => [['kind' => 'element', 'id' => 'mobile-text']],
            ]]]]],
            'appearance' => [...$source->appearance, 'backgroundTreatment' => 'soft'],
        ]);

        $response = $this->actingAs($owner)->postJson("{$base}/{$source->id}/duplicate")->assertCreated();
        $duplicatePayload = collect($response->json('data.sections'))->where('type', 'blank')->last();
        $duplicate = $event->website->sections()->findOrFail($duplicatePayload['id']);
        $source = $source->refresh();
        $this->assertNotSame($source->id, $duplicate->id);
        $this->assertSame('Section 2', $duplicate->editor_name);
        $this->assertFalse($duplicate->is_enabled);
        $this->assertSame($source->appearance, $duplicate->appearance);
        $sourceGroup = $source->content['compositions']['shared']['childFlow']['elements'][0];
        $copyGroup = $duplicate->content['compositions']['shared']['childFlow']['elements'][0];
        $this->assertNotSame($sourceGroup['id'], $copyGroup['id']);
        $this->assertSame($sourceGroup['backgroundMedia'], $copyGroup['backgroundMedia']);
        $this->assertSame($sourceGroup['appearance'], $copyGroup['appearance']);
        $this->assertSame($sourceGroup['layout'], $copyGroup['layout']);
        $this->assertNotSame($sourceGroup['children'][0]['id'], $copyGroup['children'][0]['id']);
        $this->assertNotSame($sourceGroup['children'][0]['items'][0]['id'], $copyGroup['children'][0]['items'][0]['id']);
        $this->assertSame($mediaId, $copyGroup['children'][0]['items'][0]['mediaId']);
        $this->assertSame($copyGroup['id'], $duplicate->content['compositions']['shared']['childFlow']['order'][0]['id']);
        $this->assertNotSame(
            $source->content['compositions']['custom']['mobile']['childFlow']['elements'][0]['id'],
            $duplicate->content['compositions']['custom']['mobile']['childFlow']['elements'][0]['id'],
        );
        $this->assertSame('Mobile', $duplicate->content['compositions']['custom']['mobile']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text']);
        $this->assertSame($source->sort_order + 10, $duplicate->sort_order);
    }

    public function test_singletons_reject_lifecycle_mutations_and_database_duplicates(): void
    {
        [$event, $owner] = $this->event();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $this->actingAs($owner)->postJson("{$base}/{$hero->id}/duplicate")->assertUnprocessable();
        $this->actingAs($owner)->deleteJson("{$base}/{$hero->id}")->assertUnprocessable();
        $this->actingAs($owner)->putJson("{$base}/{$hero->id}/editor-name", ['editorName' => 'Other'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($base, ['type' => 'hero'])->assertUnprocessable();

        $this->expectException(QueryException::class);
        $event->website->sections()->create([
            'type' => 'hero', 'sort_order' => 999, 'is_enabled' => true,
            'content' => app(WebsiteSectionRegistry::class)->get('hero')->defaultContent,
            'appearance' => $hero->appearance,
        ]);
    }

    public function test_hero_starter_blocks_can_all_be_deleted_saved_reloaded_and_are_not_reseeded(): void
    {
        [$event, $owner] = $this->event();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/websites/{$event->website->id}/sections/{$hero->id}";
        $elements = $hero->content['compositions']['shared']['childFlow']['elements'];

        $this->assertSame(['text', 'date', 'text'], array_column($elements, 'type'));

        foreach (array_column($elements, 'id') as $deletedId) {
            $content = $hero->refresh()->content;
            $flow = $content['compositions']['shared']['childFlow'];
            $flow['elements'] = array_values(array_filter($flow['elements'], fn (array $element): bool => $element['id'] !== $deletedId));
            $flow['order'] = array_values(array_filter($flow['order'], fn (array $reference): bool => $reference['id'] !== $deletedId));

            $content['compositions']['shared']['childFlow'] = $flow;
            $this->actingAs($owner)->putJson($url, compact('content'))->assertOk();
            $this->assertNotContains($deletedId, array_column($hero->refresh()->content['compositions']['shared']['childFlow']['elements'], 'id'));
        }

        $empty = ['elements' => [], 'order' => []];
        $this->assertSame($empty, $hero->refresh()->content['compositions']['shared']['childFlow']);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$event->website->id}")
            ->assertOk()->assertJsonPath('data.sections.0.content.compositions.shared.childFlow', $empty);

        app(InitializeWebsiteSections::class)->handle($event->website);
        $this->assertSame($empty, $hero->refresh()->content['compositions']['shared']['childFlow']);

        $accordion = ['id' => 'accordion-after-empty', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => []];
        $different = ['elements' => [$accordion], 'order' => [['kind' => 'element', 'id' => $accordion['id']]]];
        $content = $hero->refresh()->content;
        $content['compositions']['shared']['childFlow'] = $different;
        $this->actingAs($owner)->putJson($url, compact('content'))->assertOk();
        $this->assertSame($different, $hero->refresh()->content['compositions']['shared']['childFlow']);
    }

    public function test_sync_restores_required_singletons_only_and_never_coalesces_repeatable_sections(): void
    {
        [$event, $owner] = $this->event();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";
        $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated();
        $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $hero->delete();

        app(InitializeWebsiteSections::class)->handle($event->website, false);

        $this->assertSame(1, $event->website->sections()->where('type', 'hero')->count());
        $this->assertFalse($event->website->sections()->where('type', 'hero')->sole()->is_enabled);
        $this->assertSame(2, $event->website->sections()->where('type', 'blank')->count());
        $this->assertSame(0, $event->website->sections()->where('type', 'people')->count());
        $this->assertSame(0, $event->website->sections()->where('type', 'dressCode')->count());
    }

    public function test_lifecycle_routes_enforce_event_ownership_and_reject_unknown_fields(): void
    {
        [$event] = $this->event();
        $unrelated = User::factory()->create();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";
        $this->actingAs($unrelated)->postJson($base, ['type' => 'blank'])->assertForbidden();
        [$otherEvent, $otherOwner] = $this->event();
        $this->actingAs($otherOwner)->postJson($base, ['type' => 'blank'])->assertForbidden();
        $this->actingAs($otherOwner)->postJson("/api/events/{$otherEvent->id}/websites/{$otherEvent->website->id}/sections", ['type' => 'blank', 'extra' => true])->assertUnprocessable();
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }
}
