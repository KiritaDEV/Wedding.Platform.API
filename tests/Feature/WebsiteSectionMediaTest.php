<?php

namespace Tests\Feature;

use App\Actions\Websites\CreateWebsiteProject;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media-test');
    }

    public function test_draft_without_referenced_media_serializes_an_empty_media_object(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->initializeWebsite($event);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $media = json_decode($response->getContent())->data->media;

        $this->assertInstanceOf(\stdClass::class, $media);
        $this->assertSame([], get_object_vars($media));
    }

    public function test_owner_assigns_event_image_with_focal_point_and_draft_resolves_only_referenced_media(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $hero = $website->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $unused = $this->assetFor($event);

        $response = $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => $this->withHeroBackground($hero->appearance, ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]]),
        ]);

        $response->assertOk()->assertJsonPath("data.media.{$asset->id}.id", $asset->id)
            ->assertJsonPath("data.media.{$asset->id}.web.url", route('events.media.variants.show', ['event' => $event->id, 'asset' => $asset->id, 'variant' => 'web']))
            ->assertJsonMissingPath("data.media.{$unused->id}")
            ->assertJsonMissingPath("data.media.{$asset->id}.storagePath")
            ->assertJsonPath('data.sections.0.mediaCapability.mode', 'single');
        $this->assertSame(['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]], $hero->refresh()->appearance['shared']['backgroundMedia']);
    }

    public function test_assignment_authorization_and_event_scope_are_enforced(): void
    {
        [$admin, $event] = $this->eventFor(EventMembershipRole::Admin);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $otherAsset = $this->assetFor(Event::factory()->create());
        $payload = fn (string $id): array => ['appearance' => $this->withHeroBackground($hero->appearance, ['assetId' => $id])];
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";

        $this->actingAs($admin)->putJson($url, $payload($asset->id))->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->putJson($url, $payload($asset->id))->assertOk();
        $this->actingAs(User::factory()->create())->putJson($url, $payload($asset->id))->assertForbidden();
        $this->actingAs($admin)->putJson($url, $payload($otherAsset->id))->assertUnprocessable();
        $this->actingAs($admin)->putJson($url, $payload((string) Str::ulid()))->assertUnprocessable();
    }

    public function test_custom_hero_assets_round_trip_and_protect_device_only_media(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->sole();
        $desktop = $this->assetFor($event);
        $tablet = $this->assetFor($event);
        $mobile = $this->assetFor($event);
        $content = $hero->content;
        $content['compositions']['custom']['tablet'] = ['childFlow' => ['elements' => [], 'order' => []]];
        $content['compositions']['custom']['mobile'] = ['childFlow' => ['elements' => [], 'order' => []]];
        $appearance = $this->withHeroBackground($hero->appearance, ['assetId' => $desktop->id, 'focalPoint' => ['x' => .2, 'y' => .7], 'zoom' => 1.8]);
        $appearance['custom']['tablet'] = [...$appearance['shared'], 'backgroundMedia' => ['assetId' => $tablet->id, 'zoom' => 1.7]];
        $appearance['custom']['mobile'] = [...$appearance['shared'], 'backgroundMedia' => ['assetId' => $mobile->id, 'focalPoint' => ['x' => .8, 'y' => .3], 'zoom' => 1.42]];
        $url = "/api/events/{$event->id}/websites/{$hero->website_id}/sections/{$hero->id}/presentation";
        $response = $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();

        $saved = $hero->refresh()->appearance;
        $this->assertSame(1.7, $saved['custom']['tablet']['backgroundMedia']['zoom']);
        $this->assertSame(1.42, $saved['custom']['mobile']['backgroundMedia']['zoom']);
        foreach ([$desktop, $tablet, $mobile] as $asset) {
            $response->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        }
        $response->assertJsonCount(3, 'data.media');
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$mobile->id}")->assertConflict();
        $invalidNone = $saved;
        $invalidNone['custom']['mobile']['backgroundMedia'] = ['assetId' => null, 'zoom' => 1];
        $this->actingAs($owner)->putJson($url, ['content' => $content, 'appearance' => $invalidNone])->assertUnprocessable();
        $saved['custom']['mobile']['backgroundMedia'] = ['assetId' => null];
        $this->actingAs($owner)->putJson($url, ['content' => $content, 'appearance' => $saved])->assertOk();
        $this->assertNull($hero->refresh()->appearance['custom']['mobile']['backgroundMedia']['assetId']);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$mobile->id}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$desktop->id}")->assertConflict();
    }

    public function test_section_media_zoom_is_optional_bounded_and_preserved_across_presentations(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $asset = $this->assetFor($event);

        $hero = $website->sections()->where('type', 'hero')->sole();
        $base = $this->withHeroBackground($hero->appearance, ['assetId' => $asset->id]);
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        foreach ([1, 1.5, 3] as $zoom) {
            $this->actingAs($owner)->putJson($url, ['appearance' => $this->withHeroBackground($base, [...$base['shared']['backgroundMedia'], 'zoom' => $zoom])])->assertOk();
        }
        foreach ([0, -.1, .99, 3.1, 'close'] as $zoom) {
            $this->actingAs($owner)->putJson($url, ['appearance' => $this->withHeroBackground($base, [...$base['shared']['backgroundMedia'], 'zoom' => $zoom])])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson($url, ['appearance' => $base])->assertOk();
        $this->assertArrayNotHasKey('zoom', $hero->refresh()->appearance['shared']['backgroundMedia']);

        $zoomed = $this->withHeroBackground($base, [...$base['shared']['backgroundMedia'], 'zoom' => 1.8]);
        $this->actingAs($owner)->putJson($url, ['appearance' => $zoomed])->assertOk();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => ['shared' => [...$zoomed['shared'], 'height' => ['unit' => 'svh', 'value' => 100]]],
        ])->assertOk();
        $this->assertSame(1.8, $hero->refresh()->appearance['shared']['backgroundMedia']['zoom']);
    }

    public function test_referenced_asset_cannot_be_deleted_until_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $appearance = $this->withHeroBackground($hero->appearance, ['assetId' => $asset->id]);
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'media_asset_in_use')
            ->assertJsonPath('message', 'This image is used by one or more Website Projects.')
            ->assertJsonPath('usage.references.0.reference.type', 'sectionMedia');
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);

        $this->actingAs($owner)->putJson($url, ['appearance' => $this->withHeroBackground($appearance, null)])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_group_background_media_round_trips_blocks_deletion_and_rejects_unavailable_assets(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $blank = $website->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
            'backgroundMedia' => ['assetId' => $asset->id, 'focalPoint' => ['x' => .25, 'y' => .75], 'zoom' => 1.8],
            'appearance' => ['backgroundImageOpacity' => 40], 'layout' => ['direction' => 'horizontal', 'division' => '60-40'],
        ];
        $content = $this->compositionContent(['elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'group']]]);
        $url = "/api/events/{$event->id}/website/sections/{$blank->id}";
        $this->actingAs($owner)->putJson($url, compact('content'))->assertOk();
        $savedGroup = $blank->refresh()->content['compositions']['shared']['childFlow']['elements'][0];
        $this->assertSame($group, $savedGroup);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertConflict();

        $invalid = $content;
        $invalid['compositions']['shared']['childFlow']['elements'][0]['backgroundMedia']['assetId'] = (string) Str::ulid();
        $this->actingAs($owner)->putJson($url, ['content' => $invalid])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $this->compositionContent(['elements' => [[...$group, 'backgroundMedia' => null]], 'order' => [['kind' => 'element', 'id' => 'group']]])])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_group_responsive_explicit_none_round_trips_in_shared_custom_and_nested_compositions(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $blank = WebsiteSection::factory()->for($website)->forType('blank')->create([
            'sort_order' => 100,
            'editor_name' => 'Group backgrounds',
            'content' => $this->compositionContent(['elements' => [], 'order' => []]),
        ]);
        $base = $this->assetFor($event);
        $mobile = $this->assetFor($event);
        $group = fn (string $id, ?string $assetId, array $children = []): array => [
            'id' => $id, 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => $children,
            'backgroundMedia' => ['assetId' => $assetId],
        ];
        $flow = fn (array $elements): array => ['childFlow' => [
            'elements' => $elements,
            'order' => array_map(fn (array $element): array => ['kind' => 'element', 'id' => $element['id']], $elements),
        ]];
        $content = ['semantic' => [], 'compositions' => [
            'shared' => $flow([$group('shared-outer', $base->id, [$group('shared-inner', $base->id)])]),
            'custom' => ['mobile' => $flow([$group('custom-outer', $mobile->id, [$group('custom-inner', $mobile->id)])])],
        ]];
        $appearance = $blank->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$blank->id}/presentation";

        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['backgroundMedia'] = ['assetId' => null];
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['children'][0]['backgroundMedia'] = ['assetId' => null];

        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();
        $saved = $blank->refresh()->content;
        $this->assertSame($base->id, $saved['compositions']['shared']['childFlow']['elements'][0]['backgroundMedia']['assetId']);
        $this->assertNull($saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['backgroundMedia']['assetId']);
        $this->assertNull($saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['children'][0]['backgroundMedia']['assetId']);
        $draft = $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")->assertOk();
        $reloaded = collect($draft->json('data.sections'))->firstWhere('id', $blank->id);
        $this->assertNull($reloaded['content']['compositions']['custom']['mobile']['childFlow']['elements'][0]['backgroundMedia']['assetId']);

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$mobile->id}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$base->id}")->assertConflict();
    }

    public function test_usage_is_project_aware_deduplicated_and_blocks_until_every_project_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $firstProject = $this->initializeWebsite($event);
        $secondProject = app(CreateWebsiteProject::class)->handle($event, 'Modern Project', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $asset = $this->assetFor($event);
        $firstHero = $firstProject->sections()->where('type', 'hero')->sole();
        $secondHero = $secondProject->sections()->where('type', 'hero')->sole();
        $firstHero->update(['appearance' => $this->withHeroBackground($firstHero->appearance, ['assetId' => $asset->id])]);
        $secondHero->update(['appearance' => $this->withHeroBackground($secondHero->appearance, ['assetId' => $asset->id])]);

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage'];
        $this->assertCount(2, $usage['references']);
        $this->assertEqualsCanonicalizing([$firstProject->id, $secondProject->id], collect($usage['references'])->pluck('websiteProjectId')->all());
        $this->assertEqualsCanonicalizing([$firstProject->name, 'Modern Project'], collect($usage['references'])->pluck('websiteProjectName')->all());

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(2, 'usage.references');
        $firstHero->update(['appearance' => $this->withHeroBackground($firstHero->appearance, null)]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(1, 'usage.references')
            ->assertJsonPath('usage.references.0.websiteProjectId', $secondProject->id);
        $secondHero->update(['appearance' => $this->withHeroBackground($secondHero->appearance, null)]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_custom_only_media_is_hydrated_protected_and_released_when_the_branch_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $blank = WebsiteSection::factory()->for($website)->forType('blank')->create([
            'sort_order' => 100,
            'editor_name' => 'Section 1',
            'content' => $this->compositionContent(['elements' => [], 'order' => []]),
        ]);
        $asset = $this->assetFor($event);
        $content = $this->compositionContent(['elements' => [], 'order' => []]);
        $content['compositions']['custom']['mobile'] = ['childFlow' => [
            'elements' => [['id' => 'mobile-media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [
                ['id' => 'mobile-image', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Mobile only'],
            ]]],
            'order' => [['kind' => 'element', 'id' => 'mobile-media']],
        ]];
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$blank->id}";
        $presentationUrl = $url.'/presentation';
        $appearance = $blank->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];

        $this->actingAs($owner)->putJson($presentationUrl, compact('content', 'appearance'))->assertOk()
            ->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertConflict();

        unset($content['compositions']['custom']);
        unset($appearance['custom']);
        $this->actingAs($owner)->putJson($presentationUrl, compact('content', 'appearance'))->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_custom_hero_background_is_hydrated_protected_and_released_on_reset(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $sharedAsset = $this->assetFor($event);
        $desktopAsset = $this->assetFor($event);
        $content = $hero->content;
        $appearance = $this->withHeroBackground($hero->appearance, ['assetId' => $sharedAsset->id]);
        $content['compositions']['custom']['desktop'] = ['childFlow' => ['elements' => [], 'order' => []]];
        $appearance['custom']['desktop'] = [...$appearance['shared'], 'backgroundMedia' => ['assetId' => $desktopAsset->id]];
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/presentation";

        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk()
            ->assertJsonPath("data.media.{$sharedAsset->id}.id", $sharedAsset->id)
            ->assertJsonPath("data.media.{$desktopAsset->id}.id", $desktopAsset->id);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$desktopAsset->id}")->assertConflict();

        unset($content['compositions']['custom'], $appearance['custom']);
        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$desktopAsset->id}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$sharedAsset->id}")->assertConflict();
    }

    public function test_aggregate_references_hydrate_and_follow_branch_and_section_lifecycle(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $assets = collect(['H', 'A', 'B', 'C', 'D', 'E', 'F'])->mapWithKeys(fn (string $key): array => [$key => $this->assetFor($event)]);
        $media = fn (string $id, string $asset, bool $hidden = false): array => [
            'id' => $id, 'type' => 'media', 'editorName' => 'Media 1', 'isHidden' => $hidden,
            'items' => [['id' => $id.'-item', 'type' => 'image', 'mediaId' => $assets[$asset]->id, 'alt' => $asset]],
        ];
        $flow = fn (array $elements): array => ['childFlow' => ['elements' => $elements, 'order' => array_map(fn (array $element): array => ['kind' => 'element', 'id' => $element['id']], $elements)]];

        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update(['content' => ['semantic' => [], 'compositions' => [
            'shared' => $flow([$media('shared-media', 'A')]),
            'custom' => [
                'desktop' => $flow([['id' => 'desktop-group', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => $assets['B']->id], 'children' => []]]),
                'tablet' => $flow([['id' => 'tablet-people', 'type' => 'people', 'groups' => [['id' => 'family', 'people' => [['id' => 'person', 'media' => ['assetId' => $assets['C']->id]]]]]]]),
                'mobile' => $flow([$media('mobile-media', 'D', true)]),
            ],
        ]], 'appearance' => [
            'shared' => [...$hero->appearance['shared'], 'backgroundMedia' => ['assetId' => $assets['H']->id]],
            'custom' => ['desktop' => $hero->appearance['shared'], 'tablet' => $hero->appearance['shared'], 'mobile' => $hero->appearance['shared']],
        ]]);
        $blank = WebsiteSection::factory()->for($website)->forType('blank')->create([
            'sort_order' => 100, 'editor_name' => 'Section 1', 'is_enabled' => false,
            'content' => ['semantic' => [], 'compositions' => [
                'shared' => $flow([$media('blank-shared', 'E')]),
                'custom' => ['mobile' => $flow([['id' => 'outer', 'type' => 'compositionGroup', 'children' => [[
                    'id' => 'inner', 'type' => 'compositionGroup', 'children' => [$media('deep-media', 'F')],
                ]]]])],
            ]],
            'appearance' => ['shared' => WebsiteSectionAppearance::DEFAULT, 'custom' => ['mobile' => WebsiteSectionAppearance::DEFAULT]],
        ]);

        $draft = $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")->assertOk();
        foreach ($assets as $asset) {
            $draft->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
            $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertConflict();
        }

        $heroContent = $hero->refresh()->content;
        unset($heroContent['compositions']['custom']['mobile']);
        $heroAppearance = $hero->appearance;
        unset($heroAppearance['custom']['mobile']);
        $hero->update(['content' => $heroContent, 'appearance' => $heroAppearance]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$assets['D']->id}")->assertNoContent();
        foreach (['H', 'A', 'B', 'C', 'E', 'F'] as $key) {
            $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$assets[$key]->id}")->assertConflict();
        }

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$blank->id}")->assertOk();
        foreach (['E', 'F'] as $key) {
            $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$assets[$key]->id}")->assertNoContent();
        }
        foreach (['H', 'A', 'B', 'C'] as $key) {
            $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$assets[$key]->id}")->assertConflict();
        }
    }

    public function test_same_asset_occurrences_keep_composition_context_while_draft_media_is_deduplicated(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $asset = $this->assetFor($event);
        $composition = fn (string $prefix): array => ['childFlow' => [
            'elements' => [['id' => $prefix.'-media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [[
                'id' => $prefix.'-item', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => $prefix,
            ]]]],
            'order' => [['kind' => 'element', 'id' => $prefix.'-media']],
        ]];
        $hero->update(['content' => ['semantic' => [], 'compositions' => [
            'shared' => $composition('shared'),
            'custom' => ['desktop' => $composition('desktop'), 'mobile' => $composition('mobile')],
        ]], 'appearance' => [
            'shared' => [...$hero->appearance['shared'], 'backgroundMedia' => ['assetId' => $asset->id]],
            'custom' => ['desktop' => $hero->appearance['shared'], 'mobile' => $hero->appearance['shared']],
        ]]);

        $draft = $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")->assertOk();
        $draft->assertJsonCount(1, 'data.media');
        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))->firstWhere('id', $asset->id)['usage'];
        $this->assertCount(4, $usage['references']);
        $this->assertEqualsCanonicalizing(
            [null, 'shared', 'custom/desktop', 'custom/mobile'],
            collect($usage['references'])->pluck('reference.compositionScope')->all(),
        );
    }

    private function eventFor(EventMembershipRole $role): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $event->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return [$user, $event];
    }

    private function withHeroBackground(array $appearance, ?array $backgroundMedia): array
    {
        if ($backgroundMedia === null) {
            unset($appearance['shared']['backgroundMedia']);
        } else {
            $appearance['shared']['backgroundMedia'] = $backgroundMedia;
        }

        return $appearance;
    }

    private function compositionContent(array $childFlow): array
    {
        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => $childFlow]]];
    }

    private function assetFor(Event $event): MediaAsset
    {
        $asset = MediaAsset::query()->create([
            'id' => (string) Str::ulid(), 'event_id' => $event->id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'content_hash' => hash('sha256', (string) Str::ulid()),
            'storage_disk' => 'media-test', 'original_path' => 'events/'.$event->id.'/'.Str::ulid().'/original.jpg',
        ]);
        $asset->variants()->create(['id' => (string) Str::ulid(), 'variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'media-test', 'storage_path' => 'web.webp']);
        Storage::disk('media-test')->put($asset->original_path, 'original');
        Storage::disk('media-test')->put('web.webp', 'web');

        return $asset;
    }
}
