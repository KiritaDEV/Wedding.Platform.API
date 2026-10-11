<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Media\MediaAssetUsageChecker;
use App\Actions\Websites\CreateWebsiteSection;
use App\Actions\Websites\DuplicateWebsiteSection;
use App\Actions\Websites\PublishWebsite;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\InvalidPublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsitePresentation;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotReader;
use App\Website\Publishing\WebsitePublicationNotReady;
use App\Website\SharedContent;
use App\Website\WebsiteMediaReferenceProvider;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SharedContentTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Website
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Shared Schedule']);
        $project = $this->initializeWebsite($event);
        $this->actingAs($owner);

        return $project->fresh();
    }

    private function content(): array
    {
        $placement = ['id' => 'shared-placement', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'contentId' => 'schedule-content'];

        return [
            'semantic' => [],
            'sharedContentDefinitions' => [[
                'id' => 'schedule-content', 'type' => 'schedule',
                'content' => ['days' => [['id' => 'day', 'date' => null, 'title' => null, 'visible' => true, 'activities' => [['id' => 'entry', 'startTime' => '10:00', 'endTime' => null, 'title' => 'Ceremony', 'description' => 'Welcome', 'location' => null, 'mapUrl' => null, 'imageMediaId' => null, 'visible' => true]]]]],
            ]],
            'compositions' => [
                'shared' => ['childFlow' => ['elements' => [$placement], 'order' => [['kind' => 'element', 'id' => $placement['id']]]]],
                'custom' => ['mobile' => ['childFlow' => [
                    'elements' => [[...$placement, 'id' => 'mobile-placement', 'appearance' => ['outerSpacing' => ['top' => 'xl']]]],
                    'order' => [['kind' => 'element', 'id' => 'mobile-placement']],
                ]]],
            ],
        ];
    }

    public function test_strict_contract_and_section_scope(): void
    {
        $content = $this->content();
        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('blank', $content));
        foreach (['missing-id', 'duplicate', 'wrong-type', 'dangling', 'orphan', 'cycle', 'both', 'extra', 'duplicate-entry', 'entry-list'] as $kind) {
            $invalid = $content;
            switch ($kind) {
                case 'missing-id': unset($invalid['sharedContentDefinitions'][0]['id']);
                    break;
                case 'duplicate': $invalid['sharedContentDefinitions'][] = $invalid['sharedContentDefinitions'][0];
                    break;
                case 'wrong-type': $invalid['sharedContentDefinitions'][0]['type'] = 'text';
                    break;
                case 'dangling': $invalid['sharedContentDefinitions'] = [];
                    break;
                case 'orphan': $invalid['sharedContentDefinitions'][] = [...$invalid['sharedContentDefinitions'][0], 'id' => 'orphan'];
                    break;
                case 'cycle': $invalid['sharedContentDefinitions'][0]['content'] = ['contentId' => 'schedule-content'];
                    break;
                case 'both': $invalid['compositions']['shared']['childFlow']['elements'][0]['items'] = [];
                    break;
                case 'extra': $invalid['sharedContentDefinitions'][0]['secret'] = 'unexpected';
                    break;
                case 'duplicate-entry': $invalid['sharedContentDefinitions'][0]['content']['days'][] = $invalid['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0];
                    break;
                case 'entry-list': $invalid['sharedContentDefinitions'][0]['content']['days'] = ['key' => $invalid['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]];
                    break;
            }
            try {
                app(WebsiteSectionContentValidator::class)->validate('blank', $invalid);
                $this->fail('Accepted malformed shared content: '.$kind);
            } catch (ValidationException) {
                $this->assertTrue(true, $kind);
            }
        }
        $foreign = $content;
        unset($foreign['sharedContentDefinitions']);
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('blank', $foreign);
    }

    public function test_atomic_save_revision_conflict_rollback_and_published_isolation(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        $appearance = $section->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $url = "/api/events/{$project->event_id}/websites/{$project->id}/draft";
        $revision = $project->fresh()->draft_revision;
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $appearance]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        $this->assertNotSame($revision, $project->fresh()->draft_revision);
        app(PublishWebsite::class)->handle($project->event, $project);
        $snapshot = $project->fresh()->getRawOriginal('published_snapshot');
        $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['title'] = 'Later draft';
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertConflict();
        $this->assertSame('Ceremony', $section->fresh()->content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['title']);
        $current = $project->fresh()->draft_revision;
        $invalid = $content;
        $invalid['sharedContentDefinitions'] = [];
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $invalid]]], ['X-Website-Draft-Revision' => $current])->assertUnprocessable();
        $this->assertSame($current, $project->fresh()->draft_revision);
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $current])->assertOk();
        $this->assertSame($snapshot, $project->fresh()->getRawOriginal('published_snapshot'));
        $guest = app(PublishedWebsitePresentation::class)->resolve($project->event);
        $owned = collect($guest->website->sections)->firstWhere('id', $section->id);
        $this->assertSame('Ceremony', $owned->content->sharedContentDefinitions[0]->content->days[0]->activities[0]->title);
        app(PublishWebsite::class)->handle($project->event, $project);
        $updated = app(PublishedWebsitePresentation::class)->resolve($project->event);
        $this->assertSame('Later draft', collect($updated->website->sections)->firstWhere('id', $section->id)->content->sharedContentDefinitions[0]->content->days[0]->activities[0]->title);
    }

    public function test_snapshot_capture_hidden_filtering_and_decoder_integrity(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        $content['compositions']['shared']['childFlow']['elements'][0]['isHidden'] = true;
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['isHidden'] = true;
        $appearance = $section->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $section->update(['content' => $content, 'appearance' => $appearance]);
        $builder = app(PublishedWebsiteSnapshotBuilder::class);
        $snapshot = json_decode($builder->build($project->fresh())->toJson(), true);
        $this->assertCount(1, $snapshot['sharedContentDefinitions']);
        $this->assertSame($section->id, $snapshot['sharedContentDefinitions'][0]['sectionId']);
        $stored = collect($snapshot['website']['sections'])->firstWhere('id', $section->id);
        $this->assertArrayNotHasKey('sharedContentDefinitions', $stored['content']);
        $this->assertSame('schedule-content', $stored['content']['compositions']['custom']['mobile']['childFlow']['elements'][0]['contentId']);
        $this->assertSame($builder->build($project->fresh())->toJson(), $builder->build($project->fresh())->toJson());
        $reader = app(PublishedWebsiteSnapshotReader::class);
        $this->assertSame($snapshot, json_decode($reader->decode($builder->build($project->fresh())->toJson())->toJson(), true));
        foreach (['missing', 'duplicate', 'foreign-owner', 'wrong-type'] as $kind) {
            $invalid = json_decode($builder->build($project->fresh())->toJson());
            if ($kind === 'missing') {
                $invalid->sharedContentDefinitions = [];
            }
            if ($kind === 'duplicate') {
                $invalid->sharedContentDefinitions[] = $invalid->sharedContentDefinitions[0];
            }
            if ($kind === 'foreign-owner') {
                $invalid->sharedContentDefinitions[0]->sectionId = (string) Str::ulid();
            }
            if ($kind === 'wrong-type') {
                $invalid->sharedContentDefinitions[0]->type = 'text';
            }
            try {
                $reader->decode(json_encode($invalid));
                $this->fail('Accepted invalid snapshot '.$kind);
            } catch (InvalidPublishedWebsiteSnapshot) {
                $this->assertTrue(true);
            }
        }
        app(PublishWebsite::class)->handle($project->event, $project);
        foreach ([false, true] as $private) {
            $guest = app(PublishedWebsitePresentation::class)->resolve($project->event, $private);
            $visible = collect($guest->website->sections)->firstWhere('id', $section->id);
            $this->assertObjectNotHasProperty('sharedContentDefinitions', $visible->content);
            $this->assertSame([], $visible->content->compositions->shared->childFlow->elements);
        }
    }

    public function test_section_duplication_remaps_once_across_all_branches(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $appearance = $section->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $section->update(['content' => $this->content(), 'appearance' => $appearance]);
        $duplicate = app(DuplicateWebsiteSection::class)->handle($section);
        $content = $duplicate->content;
        $elements = app(SharedContent::class)->elements($content);
        $this->assertNotSame('schedule-content', $content['sharedContentDefinitions'][0]['id']);
        $this->assertSame($elements[0]['contentId'], $elements[1]['contentId']);
        $this->assertNotSame($elements[0]['id'], $elements[1]['id']);
        $this->assertNotSame('entry', $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['id']);
        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('blank', $content));
        $this->assertSame('schedule-content', $section->fresh()->content['sharedContentDefinitions'][0]['id']);
    }

    public function test_canonical_media_providers_receive_resolved_content_and_placement_scope(): void
    {
        $provider = new class implements WebsiteMediaReferenceProvider
        {
            public array $seen = [];

            public function section(string $type, array $content, array $appearance): array
            {
                return [];
            }

            public function element(array $element, string $compositionScope): array
            {
                if ($element['type'] === 'schedule') {
                    $this->seen[] = [$element['id'], $compositionScope, $element['days'][0]['activities'][0]['title']];
                }

                return [];
            }
        };
        app()->instance('shared-content-test-provider', $provider);
        app()->tag('shared-content-test-provider', WebsiteMediaReferenceProvider::class);
        $references = app(WebsiteSectionMediaReferenceExtractor::class)->extract('section', 'blank', $this->content());
        $this->assertSame([], $references);
        $this->assertCount(2, $provider->seen);
        $this->assertSame('Ceremony', $provider->seen[0][2]);
        $this->assertSame('Ceremony', $provider->seen[1][2]);
        $this->assertNotSame($provider->seen[0][0], $provider->seen[1][0]);
        $this->assertNotSame($provider->seen[0][1], $provider->seen[1][1]);
    }

    public function test_atomic_save_rejects_object_instead_of_definition_or_entry_list_without_advancing_revision(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $revision = $project->fresh()->draft_revision;
        $appearance = $section->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        foreach (['definitions', 'days', 'activities'] as $kind) {
            $content = $this->content();
            if ($kind === 'definitions') {
                $content['sharedContentDefinitions'] = (object) [];
            } elseif ($kind === 'activities') {
                $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'] = (object) [];
            } else {
                $content['sharedContentDefinitions'][0]['content']['days'] = (object) [];
            }
            $this->patchJson("/api/events/{$project->event_id}/websites/{$project->id}/draft", [
                'sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $appearance]],
            ], ['X-Website-Draft-Revision' => $revision])->assertUnprocessable();
            $this->assertSame($revision, $project->fresh()->draft_revision);
        }
    }

    public function test_incomplete_drafts_save_but_visible_untitled_publication_fails_atomically(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        unset($content['compositions']['custom']);
        $section->update(['content' => $content]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $before = $project->fresh()->getRawOriginal('published_snapshot');
        $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['title'] = null;
        $revision = $project->fresh()->draft_revision;
        $url = "/api/events/{$project->event_id}/websites/{$project->id}/draft";
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        try {
            app(PublishWebsite::class)->handle($project->event, $project);
            $this->fail('Visible untitled Activity published.');
        } catch (WebsitePublicationNotReady $exception) {
            $this->assertSame('schedule_activity_title_required', $exception->diagnostics[0]['code']);
            $this->assertSame('entry', $exception->diagnostics[0]['context']['activityId']);
        }
        $this->assertSame($before, $project->fresh()->getRawOriginal('published_snapshot'));
        $this->assertSame($project->id, $project->event->fresh()->published_website_id);
        $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['visible'] = false;
        $section->update(['content' => $content]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $guest = app(PublishedWebsitePresentation::class)->resolve($project->event);
        $owned = collect($guest->website->sections)->firstWhere('id', $section->id);
        $this->assertSame([], $owned->content->sharedContentDefinitions[0]->content->days);
        $stored = json_decode($project->fresh()->getRawOriginal('published_snapshot'), true);
        $this->assertCount(1, $stored['sharedContentDefinitions'][0]['content']['days'][0]['activities']);
        $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['visible'] = true;
        $content['sharedContentDefinitions'][0]['content']['days'][0]['visible'] = false;
        $section->update(['content' => $content]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertSame([], collect(app(PublishedWebsitePresentation::class)->resolve($project->event)->website->sections)->firstWhere('id', $section->id)->content->sharedContentDefinitions[0]->content->days);
    }

    public function test_schedule_images_are_owned_retained_in_snapshots_and_filtered_for_guests(): void
    {
        Storage::fake('schedule-test');
        $project = $this->project();
        $asset = MediaAsset::query()->create(['event_id' => $project->event_id, 'original_filename' => 'schedule.jpg',
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 5,
            'content_hash' => hash('sha256', 'schedule-image'), 'storage_disk' => 'schedule-test', 'original_path' => 'original.jpg']);
        $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 100, 'height' => 100,
            'size_bytes' => 5, 'storage_disk' => 'schedule-test', 'storage_path' => 'web.webp']);
        Storage::disk('schedule-test')->put('web.webp', 'image');
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        unset($content['compositions']['custom']);
        $content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['imageMediaId'] = $asset->id;
        $url = "/api/events/{$project->event_id}/websites/{$project->id}/draft";
        $invalid = $content;
        $invalid['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0]['imageMediaId'] = (string) Str::ulid();
        $revision = $project->fresh()->draft_revision;
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $invalid]]], ['X-Website-Draft-Revision' => $revision])->assertUnprocessable();
        $this->assertSame($revision, $project->fresh()->draft_revision);
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertSame([$asset->id], app(PublishedWebsitePresentation::class)->mediaIds(app(PublishedWebsitePresentation::class)->resolve($project->event)));
        $this->get(route('public.events.media.web', ['slug' => $project->event->slug, 'asset' => $asset->id]))->assertOk();
        $content['sharedContentDefinitions'][0]['content']['days'][0]['visible'] = false;
        $section->update(['content' => $content]);
        app(PublishWebsite::class)->handle($project->event, $project);
        foreach ([false, true] as $private) {
            $this->assertSame([], app(PublishedWebsitePresentation::class)->mediaIds(app(PublishedWebsitePresentation::class)->resolve($project->event, $private)));
        }
        $this->get(route('public.events.media.web', ['slug' => $project->event->slug, 'asset' => $asset->id]))->assertNotFound();
        $section->update(['content' => ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]]]);
        $this->assertTrue(app(MediaAssetUsageChecker::class)->isUsed($asset));
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertFalse(app(MediaAssetUsageChecker::class)->isUsed($asset));
    }

    public function test_phase4_appearance_saves_conflicts_and_publishes_independently_from_shared_content(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        $settings = ['typography' => ['activityTitle' => ['fontFamilyId' => 'inter', 'fontSize' => 'l', 'fontWeight' => 700, 'colorId' => 'terracotta-text']], 'dateFormat' => 'weekday', 'timeFormat' => '24-hour', 'showDescriptions' => false, 'showLocations' => false, 'showMapLinks' => false, 'dayGap' => 'l', 'contentWidth' => 'narrow', 'contentAlignment' => 'center'];
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = $settings;
        $mobile = ['typography' => ['activityTitle' => ['fontSize' => 's']], 'timeFormat' => '12-hour'];
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance'] = $mobile;
        $sectionAppearance = $section->appearance;
        $sectionAppearance['custom']['mobile'] = $sectionAppearance['shared'];
        $url = "/api/events/{$project->event_id}/websites/{$project->id}/draft";
        $revision = $project->fresh()->draft_revision;
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $sectionAppearance]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        $this->assertSame($content, $section->fresh()->content);
        app(PublishWebsite::class)->handle($project->event, $project);
        $frozen = $project->fresh()->getRawOriginal('published_snapshot');
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance']['typography']['activityTitle']['fontSize'] = 'xl';
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance']['showDescriptions'] = true;
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertConflict();
        $this->assertSame('l', $section->fresh()->content['compositions']['shared']['childFlow']['elements'][0]['appearance']['typography']['activityTitle']['fontSize']);
        $revision = $project->fresh()->draft_revision;
        foreach ([['fontFamilyId' => 'unknown'], ['colorId' => 'unknown'], ['fontFamilyId' => 'quicksand', 'italic' => true], ['fontSize' => 'invalid']] as $badTypography) {
            $invalid = $content;
            $invalid['compositions']['shared']['childFlow']['elements'][0]['appearance']['typography']['activityTitle'] = $badTypography;
            $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $invalid]]], ['X-Website-Draft-Revision' => $revision])->assertUnprocessable();
            $this->assertSame($revision, $project->fresh()->draft_revision);
        }
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        $this->assertSame($frozen, $project->fresh()->getRawOriginal('published_snapshot'));
        foreach ([false, true] as $private) {
            $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event, $private)->website->sections)->firstWhere('id', $section->id);
            $this->assertSame('l', $guest->content->compositions->shared->childFlow->elements[0]->appearance->typography->activityTitle->fontSize);
            $this->assertFalse($guest->content->compositions->shared->childFlow->elements[0]->appearance->showDescriptions);
            $this->assertSame('s', $guest->content->compositions->custom->mobile->childFlow->elements[0]->appearance->typography->activityTitle->fontSize);
        }
        app(PublishWebsite::class)->handle($project->event, $project);
        $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event)->website->sections)->firstWhere('id', $section->id);
        $this->assertSame('xl', $guest->content->compositions->shared->childFlow->elements[0]->appearance->typography->activityTitle->fontSize);
        $this->assertTrue($guest->content->compositions->shared->childFlow->elements[0]->appearance->showDescriptions);
        $this->assertSame($mobile, $section->fresh()->content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance']);
        $this->assertSame($content['sharedContentDefinitions'], $section->fresh()->content['sharedContentDefinitions']);
        $invalidSnapshot = json_decode($project->fresh()->getRawOriginal('published_snapshot'));
        foreach ($invalidSnapshot->website->sections as $stored) {
            if ($stored->id === $section->id) {
                $stored->content->compositions->shared->childFlow->elements[0]->appearance->typography->activityTitle = [];
            }
        }
        $this->expectException(InvalidPublishedWebsiteSnapshot::class);
        app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($invalidSnapshot));
    }

    public function test_guest_visibility_redacts_only_unneeded_shared_fields_and_never_mutates_snapshot(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        $entry = &$content['sharedContentDefinitions'][0]['content']['days'][0]['activities'][0];
        $entry['location'] = 'Hidden location';
        $entry['mapUrl'] = 'https://maps.apple.com/?q=hidden';
        unset($entry);
        $settings = ['showDescriptions' => false, 'showLocations' => false, 'showMapLinks' => false];
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = $settings;
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance'] = $settings;
        $sectionAppearance = $section->appearance;
        $sectionAppearance['custom']['mobile'] = $sectionAppearance['shared'];
        $section->update(['content' => $content, 'appearance' => $sectionAppearance]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $frozen = $project->fresh()->getRawOriginal('published_snapshot');
        foreach ([false, true] as $private) {
            $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event, $private)->website->sections)->firstWhere('id', $section->id);
            $entry = $guest->content->sharedContentDefinitions[0]->content->days[0]->activities[0];
            $this->assertNull($entry->description);
            $this->assertNull($entry->location);
            $this->assertNull($entry->mapUrl);
        }
        $this->assertSame($frozen, $project->fresh()->getRawOriginal('published_snapshot'));
        $this->assertSame($content, $section->fresh()->content);
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance']['showDescriptions'] = true;
        $section->update(['content' => $content]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event)->website->sections)->firstWhere('id', $section->id);
        $entry = $guest->content->sharedContentDefinitions[0]->content->days[0]->activities[0];
        $this->assertSame('Welcome', $entry->description);
        $this->assertNull($entry->location);
        // Local placements use their own projection too.
        unset($content['sharedContentDefinitions'], $content['compositions']['custom']);
        unset($sectionAppearance['custom']);
        $placement = &$content['compositions']['shared']['childFlow']['elements'][0];
        unset($placement['contentId']);
        $placement['days'] = $this->content()['sharedContentDefinitions'][0]['content']['days'];
        unset($placement);
        $section->update(['content' => $content, 'appearance' => $sectionAppearance]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event)->website->sections)->firstWhere('id', $section->id);
        $this->assertNull($guest->content->compositions->shared->childFlow->elements[0]->days[0]->activities[0]->description);
        $this->assertSame('Welcome', $section->fresh()->content['compositions']['shared']['childFlow']['elements'][0]['days'][0]['activities'][0]['description']);
    }

    public function test_layout_appearance_saves_atomically_and_snapshot_guest_presentation_stays_frozen(): void
    {
        $project = $this->project();
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $this->content();
        $definitions = $content['sharedContentDefinitions'];
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = ['layout' => 'timeline', 'timeline' => ['arrangement' => 'alternating', 'marker' => 'number']];
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance'] = ['layout' => 'list', 'list' => ['timePosition' => 'above']];
        $appearance = $section->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $url = "/api/events/{$project->event_id}/websites/{$project->id}/draft";
        $revision = $project->fresh()->draft_revision;
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $appearance]]], ['X-Website-Draft-Revision' => $revision])->assertOk();
        $this->assertSame($content, $section->fresh()->content);
        app(PublishWebsite::class)->handle($project->event, $project);
        $frozen = $project->fresh()->getRawOriginal('published_snapshot');
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = ['layout' => 'cards', 'cards' => ['columns' => 'grid', 'backgroundColorId' => 'terracotta-surface']];
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $revision])->assertConflict();
        $current = $project->fresh()->draft_revision;
        foreach ([[], ['layout' => 'cards', 'cards' => []], ['layout' => 'timeline', 'timeline' => []]] as $malformedAppearance) {
            $malformed = $content;
            $malformed['compositions']['shared']['childFlow']['elements'][0]['appearance'] = $malformedAppearance;
            $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $malformed]]], ['X-Website-Draft-Revision' => $current])->assertUnprocessable();
            $this->assertSame($current, $project->fresh()->draft_revision);
        }
        $invalid = $content;
        $invalid['compositions']['shared']['childFlow']['elements'][0]['appearance']['cards']['backgroundColorId'] = 'unknown-color';
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $invalid]]], ['X-Website-Draft-Revision' => $current])->assertUnprocessable();
        $this->assertSame($current, $project->fresh()->draft_revision);
        $this->patchJson($url, ['sections' => [['id' => $section->id, 'content' => $content]]], ['X-Website-Draft-Revision' => $current])->assertOk();
        $this->assertSame($definitions, $section->fresh()->content['sharedContentDefinitions']);
        $this->assertSame($frozen, $project->fresh()->getRawOriginal('published_snapshot'));
        foreach ([false, true] as $private) {
            $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event, $private)->website->sections)->firstWhere('id', $section->id);
            $this->assertSame('timeline', $guest->content->compositions->shared->childFlow->elements[0]->appearance->layout);
            $this->assertSame('list', $guest->content->compositions->custom->mobile->childFlow->elements[0]->appearance->layout);
        }
        app(PublishWebsite::class)->handle($project->event, $project);
        $guest = collect(app(PublishedWebsitePresentation::class)->resolve($project->event)->website->sections)->firstWhere('id', $section->id);
        $this->assertSame('cards', $guest->content->compositions->shared->childFlow->elements[0]->appearance->layout);
        $this->assertSame('list', $guest->content->compositions->custom->mobile->childFlow->elements[0]->appearance->layout);
        $invalidSnapshot = json_decode($project->fresh()->getRawOriginal('published_snapshot'));
        foreach ($invalidSnapshot->website->sections as $stored) {
            if ($stored->id === $section->id) {
                $stored->content->compositions->shared->childFlow->elements[0]->appearance->cards = [];
            }
        }
        $this->expectException(InvalidPublishedWebsiteSnapshot::class);
        app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($invalidSnapshot));
    }
}
