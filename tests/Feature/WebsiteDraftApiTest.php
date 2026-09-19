<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionContentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsiteDraftApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost',
        ]);
    }

    public function test_draft_get_uses_event_authorization_for_owner_admin_and_super_admin(): void
    {
        [$event, $owner] = $this->createEvent();
        $admin = User::factory()->create();
        $unrelated = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $url = "/api/events/{$event->id}/website";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($unrelated)->getJson($url)->assertForbidden();
        $this->actingAs($superAdmin)->getJson($url)->assertOk();
    }

    public function test_blank_text_and_rich_text_child_flows_round_trip_without_a_schema_bump(): void
    {
        [$event, $owner] = $this->createEvent();
        $schemaVersion = $event->website->schema_version;
        foreach (['blank'] as $type) {
            $section = $this->blankSection($event);
            $content = [
                'childFlow' => [
                    'elements' => [
                        ['id' => "{$type}-before", 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Before']]]]], 'isHidden' => true],
                        ['id' => "{$type}-rich", 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
                            ['type' => 'paragraph', 'children' => [
                                ['text' => 'Lorem ipsum '],
                                ['text' => 'blah', 'marks' => ['bold' => true]],
                                ['text' => ' blah'],
                            ]],
                            ['type' => 'paragraph', 'children' => [['text' => 'First']]],
                            ['type' => 'paragraph', 'children' => [['text' => 'Second', 'marks' => ['italic' => true]]]],
                        ]]],
                        ['id' => "{$type}-after", 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'After']]]]]],
                    ],
                    'order' => [
                        ['kind' => 'element', 'id' => "{$type}-before"],
                        ['kind' => 'element', 'id' => "{$type}-rich"],
                        ['kind' => 'element', 'id' => "{$type}-after"],
                    ],
                ],
            ];
            $content = $this->blankContent($content['childFlow']);
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$section->id}", ['content' => $content])->assertOk();
            $this->assertSame($content, $section->refresh()->content);
        }

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$event->website->id}")->assertOk()
            ->assertJsonPath('data.schemaVersion', $schemaVersion);
        $this->assertSame($schemaVersion, $event->website->refresh()->schema_version);
    }

    public function test_accordion_block_saves_and_reloads_in_blank(): void
    {
        [$event, $owner] = $this->createEvent();
        $section = $this->blankSection($event);
        $content = ['childFlow' => [
            'elements' => [[
                'id' => 'accordion-1', 'type' => 'accordion', 'editorName' => 'Accordion 1',
                'items' => [
                    ['id' => 'item-1', 'title' => 'Travel', 'content' => 'Allow extra time.'],
                    ['id' => 'item-2', 'title' => 'Parking', 'content' => 'Use the east lot.'],
                ],
            ]],
            'order' => [['kind' => 'element', 'id' => 'accordion-1']],
        ]];
        $content = $this->blankContent($content['childFlow']);

        $url = "/api/events/{$event->id}/websites/{$event->website->id}/sections/{$section->id}";
        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        $draftSection = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.sections'))->firstWhere('id', $section->id);
        $this->assertSame($content, $draftSection['content']);
    }

    public function test_schedule_block_saves_and_reloads_in_blank_and_nested_group(): void
    {
        [$event, $owner] = $this->createEvent();
        $section = $this->blankSection($event);
        $schedule = [
            'id' => 'schedule-1', 'type' => 'schedule', 'editorName' => 'Schedule 1',
            'items' => [
                ['id' => 'entry-1', 'time' => '15:30', 'title' => 'Ceremony', 'details' => 'Garden level'],
                ['id' => 'entry-2', 'time' => '18:00', 'title' => 'Reception', 'details' => 'Main hall'],
            ],
        ];
        $nested = [...$schedule, 'id' => 'schedule-2', 'editorName' => 'Schedule 2', 'items' => array_reverse($schedule['items'])];
        $content = ['childFlow' => [
            'elements' => [$schedule, ['id' => 'group-1', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
                ['id' => 'group-2', 'type' => 'compositionGroup', 'editorName' => 'Group 2', 'children' => [$nested]],
            ]]],
            'order' => [['kind' => 'element', 'id' => 'schedule-1'], ['kind' => 'element', 'id' => 'group-1']],
        ]];
        $content = $this->blankContent($content['childFlow']);

        $url = "/api/events/{$event->id}/websites/{$event->website->id}/sections/{$section->id}";
        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        $draftSection = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.sections'))->firstWhere('id', $section->id);
        $this->assertSame($content, $draftSection['content']);
    }

    public function test_people_block_remains_valid_in_blank_and_nested_group(): void
    {
        [$event, $owner] = $this->createEvent();
        $section = $this->blankSection($event);
        $people = fn (string $id): array => [
            'id' => $id,
            'type' => 'people',
            'editorName' => 'People 1',
            'groups' => [['id' => "{$id}-group", 'name' => 'Friends', 'people' => [
                ['id' => "{$id}-person", 'name' => 'Alex', 'role' => null, 'media' => null],
            ]]],
        ];
        $content = ['childFlow' => [
            'elements' => [
                $people('people-direct'),
                ['id' => 'group-1', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$people('people-nested')]],
            ],
            'order' => [
                ['kind' => 'element', 'id' => 'people-direct'],
                ['kind' => 'element', 'id' => 'group-1'],
            ],
        ]];
        $content = $this->blankContent($content['childFlow']);

        $url = "/api/events/{$event->id}/websites/{$event->website->id}/sections/{$section->id}";
        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
    }

    public function test_complete_direct_and_nested_text_state_round_trips_canonically(): void
    {
        [$event, $owner] = $this->createEvent();
        $section = $this->blankSection($event);
        $appearance = [
            'fontFamilyId' => 'inter', 'fontSize' => '7xl', 'fontWeight' => 600,
            'lineHeight' => 'relaxed', 'letterSpacing' => 'wide', 'alignment' => 'center',
            'colorId' => 'terracotta-text', 'italic' => true, 'underline' => true,
            'strikethrough' => true, 'textTransform' => 'uppercase',
            'textShadow' => 'strong', 'textShadowColorId' => 'terracotta-text',
            'glow' => 'medium', 'glowColorId' => 'terracotta-text',
        ];
        $direct = ['id' => 'direct-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Direct Text']]]]], 'isHidden' => true, 'appearance' => $appearance];
        $nested = ['id' => 'nested-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Nested Text']]]]], 'appearance' => $appearance];
        $content = [
            'childFlow' => [
                'elements' => [
                    $direct,
                    ['id' => 'outer-group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
                        ['id' => 'inner-group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$nested]],
                    ]],
                ],
                'order' => [
                    ['kind' => 'element', 'id' => 'direct-text'],
                    ['kind' => 'element', 'id' => 'outer-group'],
                ],
            ],
        ];
        $content = $this->blankContent($content['childFlow']);

        $url = "/api/events/{$event->id}/website/sections/{$section->id}";
        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        $draftSection = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$event->website->id}")->assertOk()->json('data.sections'))->firstWhere('id', $section->id);
        $this->assertSame($content, $draftSection['content']);
    }

    public function test_content_update_rejects_unknown_keys_wrong_types_and_event_or_presentation_data(): void
    {
        [$event, $owner] = $this->createEvent();
        $hero = $event->website->sections()->where('type', 'hero')->sole();

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", [
            'content' => ['headline' => 'Hi', 'subheadline' => '', 'backgroundColor' => '#fff'],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", [
            'content' => ['headline' => 'Hi', 'subheadline' => '', 'eventDate' => '2027-01-01'],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

    }

    public function test_media_empty_objects_round_trip_as_objects_and_arrays_are_rejected(): void
    {
        [$event, $owner] = $this->createEvent();
        $section = $this->blankSection($event);
        $url = "/api/events/{$event->id}/website/sections/{$section->id}";
        $element = ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [], 'presentation' => (object) [], 'appearance' => (object) []];
        $content = ['childFlow' => ['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'media']]]];
        $content = $this->blankContent($content['childFlow']);

        $this->actingAs($owner)->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['content' => $content], JSON_THROW_ON_ERROR))->assertOk();
        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $raw = $response->getContent();
        $this->assertMatchesRegularExpression('/"presentation":\{\},"appearance":\{\}/', $raw);

        foreach (['presentation', 'appearance'] as $field) {
            $invalid = $content;
            $invalid['compositions']['shared']['childFlow']['elements'][0][$field] = [];
            $this->actingAs($owner)->putJson($url, ['content' => $invalid])->assertUnprocessable();
        }
    }

    public function test_direct_and_nested_media_round_trip_and_reject_foreign_event_assets(): void
    {
        [$event, $owner] = $this->createEvent();
        [$foreignEvent] = $this->createEvent();
        $asset = MediaAsset::query()->create(['event_id' => $event->id, 'created_by_user_id' => $owner->id, 'original_filename' => 'ours.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => 'test/ours.jpg']);
        $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'local', 'storage_path' => 'test/ours.webp']);
        $foreign = MediaAsset::query()->create(['event_id' => $foreignEvent->id, 'original_filename' => 'foreign.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => 'test/foreign.jpg']);
        $section = $this->blankSection($event);
        $media = ['id' => 'direct-media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'image-one', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Portrait'], ['id' => 'image-two', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Detail']], 'presentation' => ['mode' => 'carousel', 'carousel' => ['loop' => true]]];
        $nested = [
            'id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [[
                'id' => 'nested-media', 'type' => 'media', 'editorName' => 'Media 1',
                'items' => [
                    ['id' => 'image-three', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Portrait'],
                    ['id' => 'image-four', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Detail'],
                    ['id' => 'image-five', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Flowers'],
                ],
                'presentation' => ['mode' => 'carousel'],
            ]],
        ];
        $content = ['childFlow' => ['elements' => [$media, $nested], 'order' => [['kind' => 'element', 'id' => 'direct-media'], ['kind' => 'element', 'id' => 'group']]]];
        $content = $this->blankContent($content['childFlow']);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$event->website->id}/sections/{$section->id}", ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        $draftSection = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.sections'))->firstWhere('id', $section->id);
        $this->assertSame($content, $draftSection['content']);
        $this->assertArrayHasKey($asset->id, $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.media'));

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$section->id}", ['content' => $draftSection['content']])->assertOk();
        $this->assertSame($content, $section->refresh()->content);

        $foreignContent = $content;
        $foreignContent['compositions']['shared']['childFlow']['elements'][0]['items'][0]['mediaId'] = $foreign->id;
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$section->id}", ['content' => $foreignContent])->assertUnprocessable();
    }

    public function test_gallery_accepts_canonical_images_and_rejects_legacy_shape_and_unknown_sections(): void
    {
        [$event, $owner] = $this->createEvent();
        $gallery = $event->website->sections()->where('type', 'gallery')->sole();
        $asset = MediaAsset::query()->create(['event_id' => $event->id, 'created_by_user_id' => $owner->id, 'original_filename' => 'gallery.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => 'test/gallery.jpg']);
        $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'local', 'storage_path' => 'test/gallery.webp']);
        $content = ['semantic' => ['items' => [['id' => 'photo-one', 'type' => 'image', 'mediaId' => $asset->id, 'focalPoint' => ['x' => .25, 'y' => .75], 'zoom' => 1.5]]], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]]]];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", ['content' => $content])->assertOk();
        $this->assertSame($content, $gallery->refresh()->content);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", [
            'content' => ['semantic' => ['heading' => '', 'items' => [['url' => 'https://example.test/image.jpg']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('content.semantic');

        $legacy = WebsiteSection::factory()->for($event->website)->forType('customLegacySection')->create();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$legacy->id}", [
            'content' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    }

    public function test_gallery_item_management_save_reload_sequence_preserves_composition_and_assets(): void
    {
        [$event, $owner] = $this->createEvent();
        $gallery = $event->website->sections()->where('type', 'gallery')->sole();
        $assets = collect(['first', 'second'])->map(function (string $name) use ($event, $owner): MediaAsset {
            $asset = MediaAsset::query()->create(['event_id' => $event->id, 'created_by_user_id' => $owner->id, 'original_filename' => "{$name}.jpg", 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => "test/{$name}.jpg"]);
            $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'local', 'storage_path' => "test/{$name}.webp"]);

            return $asset;
        });
        $compositions = $gallery->content['compositions'];
        $saveReload = function (array $items) use ($event, $owner, $gallery, $compositions): array {
            $content = ['semantic' => ['items' => $items], 'compositions' => $compositions];
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", ['content' => $content])->assertOk();
            $draft = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data');
            $reloaded = collect($draft['sections'])->firstWhere('id', $gallery->id)['content'];
            $this->assertSame($content, $reloaded);
            foreach ($items as $item) {
                $this->assertArrayHasKey($item['mediaId'], $draft['media']);
            }

            return $reloaded['semantic']['items'];
        };
        $first = ['id' => 'gallery-first', 'type' => 'image', 'mediaId' => $assets[0]->id, 'focalPoint' => ['x' => .2, 'y' => .8], 'zoom' => 1.5];
        $second = ['id' => 'gallery-second', 'type' => 'image', 'mediaId' => $assets[1]->id];
        $items = $saveReload([$first]);
        $items[0]['focalPoint'] = ['x' => .125, 'y' => .875];
        $items[0]['zoom'] = 2.3;
        $items = $saveReload($items);
        $this->assertSame(['x' => .125, 'y' => .875], $items[0]['focalPoint']);
        $this->assertSame(2.3, $items[0]['zoom']);
        unset($items[0]['focalPoint'], $items[0]['zoom']);
        $items = $saveReload($items);
        $this->assertArrayNotHasKey('focalPoint', $items[0]);
        $this->assertArrayNotHasKey('zoom', $items[0]);
        $items = $saveReload([$first]);
        $items = $saveReload([...$items, $second]);
        $items = $saveReload([$items[1], $items[0]]);
        $duplicate = [...$items[1], 'id' => 'gallery-duplicate'];
        $items = $saveReload([...$items, $duplicate]);
        $items[1]['mediaId'] = $assets[1]->id;
        unset($items[1]['focalPoint'], $items[1]['zoom']);
        $items = $saveReload($items);
        $this->assertSame($first['focalPoint'], $items[2]['focalPoint']);
        $this->assertSame($first['zoom'], $items[2]['zoom']);
        $items = $saveReload([$items[0], $items[2]]);
        $saveReload([]);
        foreach ($assets as $asset) {
            $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        }
    }

    public function test_gallery_composition_has_one_specialized_reference_and_no_responsive_semantic_items(): void
    {
        [$event, $owner] = $this->createEvent();
        $gallery = $event->website->sections()->where('type', 'gallery')->sole();
        $galleryContent = ['semantic' => ['items' => []], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]]]];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", ['content' => $galleryContent])->assertOk();
        $this->assertSame($galleryContent, $gallery->refresh()->content);

        foreach ([
            ['semantic' => ['items' => []], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]],
            ['semantic' => ['items' => [], 'mobile' => ['items' => []]], 'compositions' => $galleryContent['compositions']],
            ['semantic' => ['items' => []], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content'], ['kind' => 'specialized', 'key' => 'content']]]]]],
        ] as $invalid) {
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", ['content' => $invalid])->assertUnprocessable();
        }

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}/enabled", ['isEnabled' => false])->assertOk();
        $this->assertSame($galleryContent, $gallery->refresh()->content);
    }

    public function test_gallery_enforces_item_limit_canonical_shape_and_asset_ownership(): void
    {
        [$event, $owner] = $this->createEvent();
        [$foreignEvent] = $this->createEvent();
        $gallery = $event->website->sections()->where('type', 'gallery')->sole();
        $asset = MediaAsset::query()->create(['event_id' => $event->id, 'created_by_user_id' => $owner->id, 'original_filename' => 'ours.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => 'test/ours.jpg']);
        $foreign = MediaAsset::query()->create(['event_id' => $foreignEvent->id, 'original_filename' => 'foreign.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 100, 'storage_disk' => 'local', 'original_path' => 'test/foreign.jpg']);
        $flow = ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]];
        $make = fn (array $items): array => ['semantic' => ['items' => $items], 'compositions' => ['shared' => ['childFlow' => $flow]]];
        $items = array_map(fn (int $index): array => ['id' => "item-{$index}", 'type' => 'image', 'mediaId' => $asset->id], range(1, 24));
        $url = "/api/events/{$event->id}/website/sections/{$gallery->id}";

        $this->actingAs($owner)->putJson($url, ['content' => $make($items)])->assertOk();
        $tooMany = [...$items, [...$items[0], 'id' => 'item-25']];
        $this->actingAs($owner)->putJson($url, ['content' => $make($tooMany)])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([$items[0], $items[0]])])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([[...$items[0], 'alt' => 'Legacy']])])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([[...$items[0], 'decorative' => true]])])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([[...$items[0], 'caption' => 'Unsupported']])])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([[...$items[0], 'mediaId' => $foreign->id]])])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make([[...$items[0], 'mediaId' => (string) Str::ulid()]])])->assertUnprocessable();
        $badFocalPoint = [[...$items[0], 'focalPoint' => ['x' => 1.1, 'y' => .5]]];
        $badZoom = [[...$items[0], 'zoom' => 3.1]];
        $this->actingAs($owner)->putJson($url, ['content' => $make($badFocalPoint)])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => $make($badZoom)])->assertUnprocessable();
    }

    public function test_enable_disable_preserves_content_and_checks_template_capability(): void
    {
        [$event, $owner] = $this->createEvent();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $hero->update(['content' => ['headline' => 'Keep', 'subheadline' => 'Me']]);
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/enabled";

        $this->actingAs($owner)->putJson($url, ['isEnabled' => false])
            ->assertOk()->assertJsonPath('data.sections.0.isEnabled', false);
        $this->assertSame(['headline' => 'Keep', 'subheadline' => 'Me'], $hero->refresh()->content);
        $this->actingAs($owner)->putJson($url, ['isEnabled' => true])->assertOk();

        $legacy = WebsiteSection::factory()->for($event->website)->forType('customLegacySection')->disabled()->create();
        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$legacy->id}/enabled",
            ['isEnabled' => true],
        )->assertUnprocessable()->assertJsonValidationErrors('isEnabled');
        $this->assertFalse($legacy->refresh()->is_enabled);
    }

    public function test_reorder_requires_exact_set_and_normalizes_dense_order(): void
    {
        [$event, $owner] = $this->createEvent();
        $sections = $event->website->sections()->get();
        $reversed = $sections->pluck('id')->reverse()->values()->all();
        $url = "/api/events/{$event->id}/website/sections/order";

        $this->actingAs($owner)->putJson($url, ['sectionIds' => $reversed])
            ->assertOk()->assertJsonPath('data.sections.0.id', $reversed[0]);
        $this->assertSame(range(10, count($sections) * 10, 10), $event->website->sections()->pluck('sort_order')->all());

        $this->actingAs($owner)->putJson($url, ['sectionIds' => array_slice($reversed, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds');
        $this->actingAs($owner)->putJson($url, ['sectionIds' => [$reversed[0], $reversed[0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds.1');

        [$otherEvent] = $this->createEvent();
        $foreignId = $otherEvent->website->sections()->value('id');
        $extra = $reversed;
        $extra[0] = $foreignId;
        $this->actingAs($owner)->putJson($url, ['sectionIds' => $extra])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds');
    }

    public function test_cross_event_section_id_returns_not_found_and_unrelated_mutation_is_forbidden(): void
    {
        [$event, $owner] = $this->createEvent();
        [$otherEvent] = $this->createEvent();
        $foreignSection = $otherEvent->website->sections()->first();
        $content = $foreignSection->content;

        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$foreignSection->id}",
            ['content' => ['headline' => 'Attack', 'subheadline' => '']],
        )->assertNotFound();
        $this->assertSame($content, $foreignSection->refresh()->content);

        $unrelated = User::factory()->create();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $this->actingAs($unrelated)->putJson(
            "/api/events/{$event->id}/website/sections/{$hero->id}/enabled",
            ['isEnabled' => false],
        )->assertForbidden();
    }

    public function test_story_content_is_rejected_by_the_api_validator(): void
    {
        $this->expectException(ValidationException::class);

        app(WebsiteSectionContentValidator::class)->validate('story', []);
    }

    public function test_people_content_is_rejected_as_a_section_by_the_api_validator(): void
    {
        $this->expectException(ValidationException::class);

        app(WebsiteSectionContentValidator::class)->validate('people', []);
    }

    /** @return array{Event, User} */
    private function createEvent(): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }

    private function blankSection(Event $event): WebsiteSection
    {
        return WebsiteSection::factory()->for($event->website)->forType('blank')->create([
            'sort_order' => 100,
            'editor_name' => 'Section 1',
            'content' => $this->blankContent(['elements' => [], 'order' => []]),
        ]);
    }

    private function blankContent(array $childFlow): array
    {
        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => $childFlow]]];
    }
}
