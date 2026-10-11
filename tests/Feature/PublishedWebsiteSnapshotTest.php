<?php

namespace Tests\Feature;

use App\Actions\Websites\InitializeWebsiteSections;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\RenderableWebsite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublishedWebsiteSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Website
    {
        $project = Website::factory()->create();
        app(InitializeWebsiteSections::class)->handle($project);
        $project->event->update([
            'name' => 'Frozen Event', 'event_date' => '2026-12-22',
            'start_time' => '18:30', 'time_zone' => 'Asia/Manila',
        ]);

        return $project->fresh();
    }

    private function snapshot(Website $project): array
    {
        return json_decode(app(PublishedWebsiteSnapshotBuilder::class)->build($project)->toJson(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_projection_matches_current_rendering_contract_without_delivered_media(): void
    {
        $project = $this->project();
        $snapshot = $this->snapshot($project);
        $rendered = (new RenderableWebsite($project->fresh()))->toArray(new Request);
        unset($rendered['media']);
        $this->assertSame(json_decode(json_encode($rendered, JSON_THROW_ON_ERROR), true), $snapshot['website']);
        $this->assertSame(['id' => $project->id, 'eventId' => $project->event_id, 'name' => $project->name], $snapshot['project']);
        $this->assertSame([
            'id' => $project->event_id, 'name' => 'Frozen Event', 'slug' => $project->event->slug,
            'type' => 'wedding', 'eventDate' => '2026-12-22', 'startTime' => '18:30',
            'timeZone' => 'Asia/Manila', 'startsAtUtc' => '2026-12-22T10:30:00Z',
        ], $snapshot['event']);
        $this->assertSame([], $snapshot['sharedContentDefinitions']);
    }

    public function test_all_compositions_hidden_blocks_disabled_sections_and_media_identities_are_retained(): void
    {
        $project = $this->project();
        $section = $project->sections()->where('type', 'hero')->sole();
        $content = $section->content;
        $appearance = $section->appearance;
        $mediaIds = [];
        foreach (['shared', 'desktop', 'tablet', 'mobile'] as $index => $branch) {
            $id = 'media-'.$branch;
            $assetId = '01K0000000000000000000000'.$index;
            $mediaIds[] = $assetId;
            $composition = ['childFlow' => [
                'elements' => [[
                    'id' => $id, 'type' => 'media', 'editorName' => 'Media 1', 'isHidden' => true,
                    'items' => [['id' => 'item-'.$branch, 'type' => 'image', 'mediaId' => $assetId, 'alt' => $branch]],
                ]],
                'order' => [['kind' => 'element', 'id' => $id]],
            ]];
            if ($branch === 'shared') {
                $content['compositions']['shared'] = $composition;
            } else {
                $content['compositions']['custom'][$branch] = $composition;
                $appearance['custom'][$branch] = $appearance['shared'];
            }
        }
        $section->update(['content' => $content, 'appearance' => $appearance, 'is_enabled' => false]);
        $snapshot = $this->snapshot($project);
        $frozen = collect($snapshot['website']['sections'])->firstWhere('id', $section->id);
        $this->assertSame($content['compositions'], $frozen['content']['compositions']);
        $this->assertSame($appearance, $frozen['appearance']);
        $this->assertFalse($frozen['isEnabled']);
        $this->assertSame($mediaIds, $snapshot['mediaAssetIds']);
        $this->assertArrayNotHasKey('media', $snapshot['website']);
    }

    public function test_order_is_stable_by_sort_order_then_section_id(): void
    {
        $project = $this->project();
        $project->sections()->update(['sort_order' => 7]);
        $expected = $project->sections()->pluck('id')->all();
        $this->assertSame($expected, array_column($this->snapshot($project)['website']['sections'], 'id'));
        $first = $project->sections()->first();
        $first->update(['sort_order' => 99]);
        $expected = $project->sections()->pluck('id')->all();
        $this->assertSame($expected, array_column($this->snapshot($project)['website']['sections'], 'id'));
    }

    public function test_custom_design_settings_and_resolved_section_context_are_frozen(): void
    {
        $project = $this->project();
        $settings = [
            'colorTheme' => 'sage', 'fontSet' => 'romantic', 'artStyle' => 'botanical',
            'projectDefaults' => ['headingFontId' => 'editorial-serif', 'accentColorId' => 'sage-accent'],
            'customColors' => [['id' => 'project-color-01K00000000000000000000000', 'value' => '#123456']],
        ];
        // Existing design saves retain colors authored through the separate library.
        $project->update(['design_settings' => [...$project->design_settings, 'customColors' => $settings['customColors']]]);
        app(UpdateWebsiteDesignSettings::class)->handle($project, $settings);
        $snapshot = $this->snapshot($project);
        $this->assertSame($settings, $snapshot['website']['designSettings']);
        $this->assertSame('sage-accent', $snapshot['website']['projectDesignDefaults']['accentColorId']);
        $this->assertSame('editorial-serif', $snapshot['website']['sections'][0]['resolvedDesignContext']['headingFontId']);
    }

    public function test_nested_groups_people_and_gallery_media_are_in_the_unique_manifest(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->sole();
        $assetId = '01K00000000000000000000000';
        $group = [
            'id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true,
            'backgroundMedia' => ['assetId' => $assetId],
            'children' => [[
                'id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Group 1',
                'children' => [[
                    'id' => 'people', 'type' => 'people', 'editorName' => 'People 1',
                    'groups' => [['id' => 'family', 'name' => 'Family', 'people' => [['id' => 'person', 'name' => 'Alex', 'media' => ['assetId' => $assetId]]]]],
                ]],
            ]],
        ];
        $hero->update(['content' => ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => [
            'elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'outer']],
        ]]]]]);
        $gallery = $project->sections()->where('type', 'gallery')->sole();
        $content = $gallery->content;
        $content['semantic']['items'] = [['id' => 'gallery-photo', 'type' => 'image', 'mediaId' => $assetId]];
        $gallery->update(['content' => $content, 'is_enabled' => false]);
        $snapshot = $this->snapshot($project);
        $this->assertSame([$assetId], $snapshot['mediaAssetIds']);
        $this->assertSame($group, $snapshot['website']['sections'][0]['content']['compositions']['shared']['childFlow']['elements'][0]);
    }

    public function test_deterministic_detached_projection_uses_saved_state_and_does_not_write(): void
    {
        $project = $this->project()->load('sections', 'event');
        $before = [DB::table('websites')->get()->toJson(), DB::table('website_sections')->get()->toJson(), DB::table('events')->get()->toJson()];
        DB::enableQueryLog();
        $first = app(PublishedWebsiteSnapshotBuilder::class)->build($project);
        $project->name = 'Unsaved project';
        $project->event->name = 'Unsaved event';
        $project->sections->first()->content = [];
        $second = app(PublishedWebsiteSnapshotBuilder::class)->build($project);
        $this->assertSame($first->toJson(), $second->toJson());
        $tree = $first->jsonSerialize();
        $tree->event->name = 'Mutated copy';
        $this->assertSame($second->toJson(), $first->toJson());
        $this->assertSame($before, [DB::table('websites')->get()->toJson(), DB::table('website_sections')->get()->toJson(), DB::table('events')->get()->toJson()]);
        $this->assertSame('Unsaved project', $project->name);
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1)));
        DB::disableQueryLog();
    }

    public function test_section_background_media_identity_is_captured_without_asset_storage_details(): void
    {
        $project = $this->project();
        $asset = MediaAsset::query()->create([
            'event_id' => $project->event_id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100,
            'content_hash' => hash('sha256', 'snapshot-test'), 'storage_disk' => 'private',
            'original_path' => 'secret-storage-location/image.jpg',
        ]);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $appearance = $hero->appearance;
        $appearance['shared']['backgroundMedia'] = ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.2, 'y' => 0.8], 'zoom' => 1.5];
        $hero->update(['appearance' => $appearance]);
        $snapshot = app(PublishedWebsiteSnapshotBuilder::class)->build($project);
        $this->assertSame([$asset->id], $snapshot->jsonSerialize()->mediaAssetIds);
        $this->assertStringNotContainsString('secret-storage-location', $snapshot->toJson());
        $this->assertStringNotContainsString('original_path', $snapshot->toJson());
    }

    public function test_invalid_stored_design_is_rejected_without_silently_defaulting(): void
    {
        $project = $this->project();
        $project->update(['design_settings' => [...$project->design_settings, 'colorTheme' => 'invalid']]);
        $this->expectException(ValidationException::class);
        $this->snapshot($project);
    }

    public function test_operational_fields_and_request_state_are_not_captured_and_event_information_is_frozen(): void
    {
        $project = $this->project();
        $project->event->update(['rsvp_is_open' => false, 'rsvp_deadline' => '2026-12-20', 'published_website_id' => $project->id]);
        request()->merge(['invitationToken' => 'secret', 'guests' => [['name' => 'Guest secret']], 'rsvpResponses' => ['yes']]);
        $snapshot = app(PublishedWebsiteSnapshotBuilder::class)->build($project);
        $this->actingAs(User::factory()->create());
        $this->assertSame($snapshot->toJson(), app(PublishedWebsiteSnapshotBuilder::class)->build($project)->toJson());
        foreach (['rsvpIsOpen', 'rsvpDeadline', 'publishedWebsiteId', 'invitationToken', 'guests', 'rsvpResponses', 'secret', 'web'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $snapshot->toJson());
        }
        $project->event->update(['name' => 'Changed Event']);
        $this->assertSame('Frozen Event', $snapshot->jsonSerialize()->event->name);
        $this->assertSame('Changed Event', $this->snapshot($project)['event']['name']);
    }

    public function test_obsolete_content_is_rejected_instead_of_using_a_runtime_adapter(): void
    {
        $project = $this->project();
        $project->sections()->where('type', 'rsvp')->update(['content' => json_encode(['semantic' => ['heading' => 'Legacy']])]);
        $this->expectException(ValidationException::class);
        $this->snapshot($project);
    }
}
