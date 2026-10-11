<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Actions\Websites\PublishWebsite;
use App\Models\Event;
use App\Models\Website;
use App\Website\Publishing\InvalidPublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotReader;
use App\Website\Publishing\PublishedWebsiteSnapshotStore;
use App\Website\WebsiteTemplateRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PublishedWebsiteSnapshotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Website
    {
        $project = Website::factory()->create();
        app(InitializeWebsiteSections::class)->handle($project);

        return $project;
    }

    private function candidate(Website $project): PublishedWebsiteSnapshot
    {
        return app(PublishedWebsiteSnapshotBuilder::class)->build($project);
    }

    private function store(): PublishedWebsiteSnapshotStore
    {
        return app(PublishedWebsiteSnapshotStore::class);
    }

    public function test_empty_state_returns_null_without_draft_fallback(): void
    {
        $project = $this->project();
        $this->assertNull($this->store()->read($project));
        $this->assertNull($project->fresh()->snapshot_published_at);
        $this->assertNull($project->fresh()->published_snapshot);
    }

    public function test_valid_snapshot_round_trips_and_has_minimal_metadata_and_safe_model_serialization(): void
    {
        $project = $this->project();
        $candidate = $this->candidate($project);
        $this->store()->replace($project, $candidate);
        $saved = $project->fresh();
        $this->assertSame($candidate->toJson(), $this->store()->read($project)->toJson());
        $this->assertInstanceOf(\stdClass::class, $saved->published_snapshot);
        $this->assertInstanceOf(\stdClass::class, $saved->published_snapshot->website->designSettings->projectDefaults);
        $this->assertInstanceOf(CarbonImmutable::class, $saved->snapshot_published_at);
        $this->assertArrayNotHasKey('published_snapshot', $saved->toArray());
        $this->assertArrayNotHasKey('snapshot_published_at', $saved->toArray());
        $this->assertFalse($saved->isFillable('published_snapshot'));
        $this->assertFalse($saved->isFillable('snapshot_published_at'));
    }

    public function test_replacement_keeps_one_snapshot_and_does_not_change_active_event_project(): void
    {
        $project = $this->project();
        $this->store()->replace($project, $this->candidate($project));
        $publishedAt = $project->fresh()->snapshot_published_at;
        $project->update(['name' => 'Replacement']);
        $candidate = $this->candidate($project);
        $this->travel(1)->minutes();
        $this->store()->replace($project, $candidate);
        $this->travelBack();
        $this->assertSame($candidate->toJson(), $this->store()->read($project)->toJson());
        $this->assertDatabaseCount('websites', 1);
        $this->assertTrue($project->fresh()->snapshot_published_at->greaterThan($publishedAt));
        $this->assertNull($project->event->fresh()->published_website_id);
    }

    public function test_draft_edits_and_section_deletion_do_not_change_stored_presentation(): void
    {
        $project = $this->project();
        $candidate = $this->candidate($project);
        $this->store()->replace($project, $candidate);
        $project->sections()->delete();
        $project->update(['name' => 'Changed draft', 'design_settings' => []]);
        $project->event->update(['name' => 'Changed Event']);
        DB::enableQueryLog();
        $this->assertSame($candidate->toJson(), $this->store()->read($project)->toJson());
        $queries = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertStringNotContainsString('website_sections', $queries);
        $this->assertStringNotContainsString('media_assets', $queries);
        $this->assertStringNotContainsString('"events"', $queries);
    }

    public function test_malformed_storage_is_detected_without_fallback(): void
    {
        $project = $this->project();
        DB::table('websites')->where('id', $project->id)->update(['published_snapshot' => '{}']);
        $this->expectException(InvalidPublishedWebsiteSnapshot::class);
        $this->store()->read($project);
    }

    public function test_invalid_candidate_does_not_replace_payload_or_timestamp(): void
    {
        $project = $this->project();
        $valid = $this->candidate($project);
        $this->store()->replace($project, $valid);
        $before = $project->fresh()->getRawOriginal();
        $tree = $valid->jsonSerialize();
        $event = get_object_vars($tree->event);
        $event['invitationToken'] = 'secret';
        $invalid = new PublishedWebsiteSnapshot(get_object_vars($tree->project), $event, get_object_vars($tree->website), $tree->mediaAssetIds);
        try {
            $this->store()->replace($project, $invalid);
            $this->fail('Invalid snapshot accepted.');
        } catch (InvalidPublishedWebsiteSnapshot) {
            $this->assertSame($before, $project->fresh()->getRawOriginal());
        }
    }

    public function test_failed_save_preserves_previous_snapshot_and_metadata(): void
    {
        $project = $this->project();
        $this->store()->replace($project, $this->candidate($project));
        $project->update(['name' => 'Replacement']);
        $candidate = $this->candidate($project);
        $before = $project->fresh()->getRawOriginal();
        Website::saving(function (Website $saving) use ($project): void {
            if ($saving->id === $project->id && $saving->isDirty('published_snapshot')) {
                throw new RuntimeException('Simulated database save failure.');
            }
        });
        try {
            $this->store()->replace($project, $candidate);
            $this->fail('Save failure ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database save failure.', $exception->getMessage());
            $this->assertSame($before, $project->fresh()->getRawOriginal());
        }
    }

    public function test_outer_transaction_can_coordinate_replacement_and_roll_it_back(): void
    {
        $project = $this->project();
        $this->store()->replace($project, $this->candidate($project));
        $before = $project->fresh()->getRawOriginal();
        try {
            DB::transaction(function () use ($project): void {
                $project->update(['name' => 'Replacement']);
                $this->store()->replace($project, $this->candidate($project));
                throw new RuntimeException('Outer transaction failed.');
            });
        } catch (RuntimeException) {
            $this->assertSame($before, $project->fresh()->getRawOriginal());
        }
    }

    public function test_projects_are_isolated_and_foreign_candidates_are_rejected(): void
    {
        $first = $this->project();
        $second = Website::factory()->create(['event_id' => $first->event_id]);
        app(InitializeWebsiteSections::class)->handle($second);
        $firstSnapshot = $this->candidate($first);
        $secondSnapshot = $this->candidate($second);
        $this->store()->replace($first, $firstSnapshot);
        $this->store()->replace($second, $secondSnapshot);
        try {
            $this->store()->replace($second, $firstSnapshot);
            $this->fail('Foreign snapshot accepted.');
        } catch (InvalidPublishedWebsiteSnapshot) {
            $this->assertSame($firstSnapshot->toJson(), $this->store()->read($first)->toJson());
            $this->assertSame($secondSnapshot->toJson(), $this->store()->read($second)->toJson());
        }
    }

    public function test_project_deletion_removes_its_snapshot_with_the_row(): void
    {
        $project = $this->project();
        $this->store()->replace($project, $this->candidate($project));
        $project->delete();
        $this->assertDatabaseMissing('websites', ['id' => $project->id]);
    }

    public function test_reader_rejects_nested_unknown_fields_and_preserves_frozen_descriptor_values(): void
    {
        $tree = $this->candidate($this->project())->jsonSerialize();
        $tree->website->template->displayName = 'Frozen Template label';
        $decoded = app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($tree, JSON_THROW_ON_ERROR));
        $this->assertSame('Frozen Template label', $decoded->jsonSerialize()->website->template->displayName);
        $tree->website->template->capabilities->credentials = 'secret';
        $this->expectException(InvalidPublishedWebsiteSnapshot::class);
        app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($tree, JSON_THROW_ON_ERROR));
    }

    public function test_reader_rejects_malformed_payloads_without_querying_drafts(): void
    {
        $valid = $this->candidate($this->project())->toJson();
        $mutations = [
            fn ($tree) => $tree->mediaAssetIds = new \stdClass,
            fn ($tree) => $tree->website->schemaVersion = '5',
            fn ($tree) => $tree->website->sections[0]->content->compositions->shared->childFlow->elements[0]->invitationToken = 'secret',
            fn ($tree) => $tree->website->sections[0]->isEnabled = 1,
            fn ($tree) => $tree->mediaAssetIds = ['01K00000000000000000000000'],
            fn ($tree) => $tree->website->sections = array_reverse($tree->website->sections),
            fn ($tree) => $tree->website->media = ['url' => 'https://example.test/temporary'],
            fn ($tree) => $tree->event->startsAtUtc = '2026-01-01T00:00:00Z',
        ];
        foreach ($mutations as $mutation) {
            $tree = json_decode($valid);
            $mutation($tree);
            try {
                app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($tree, JSON_THROW_ON_ERROR));
                $this->fail('Malformed payload accepted.');
            } catch (InvalidPublishedWebsiteSnapshot) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidPublishedWebsiteSnapshot::class);
        app(PublishedWebsiteSnapshotReader::class)->decode('{');
    }

    public function test_cancelled_save_is_reported_and_preserves_previous_storage(): void
    {
        $project = $this->project();
        $this->store()->replace($project, $this->candidate($project));
        $project->update(['name' => 'Replacement']);
        $candidate = $this->candidate($project);
        $before = $project->fresh()->getRawOriginal();
        Website::saving(fn (Website $saving) => $saving->id === $project->id && $saving->isDirty('published_snapshot') ? false : null);
        try {
            $this->store()->replace($project, $candidate);
            $this->fail('Cancelled save ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Snapshot replacement was cancelled.', $exception->getMessage());
            $this->assertSame($before, $project->fresh()->getRawOriginal());
        }
    }

    public function test_identical_snapshot_replacement_preserves_responses_and_malformed_storage_fails_closed(): void
    {
        $project = $this->project();
        $event = $project->event;
        app(PublishWebsite::class)->handle($event, $project);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Private Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $publicUrl = '/api/public/events/'.$event->slug.'/site';
        $publicBefore = $this->getJson($publicUrl)->assertOk()->json('data');
        $privateBefore = $this->postJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data');
        $this->store()->replace($project, $this->candidate($project));
        $this->assertSame($publicBefore, $this->getJson($publicUrl)->assertOk()->json('data'));
        $this->assertSame($privateBefore, $this->postJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data'));
        $this->assertSame($project->id, $event->fresh()->published_website_id);
        // Phase 3 requires guest reads to reject malformed storage without a draft fallback.
        DB::table('websites')->where('id', $project->id)->update(['published_snapshot' => '{}']);
        $this->getJson($publicUrl)->assertNotFound();
        $this->postJson('/api/private-invitations/site', ['token' => $token])->assertNotFound();
    }

    public function test_modern_template_and_custom_composition_media_round_trip(): void
    {
        $project = app(CreateWebsiteProject::class)->handle(Event::factory()->create(), 'Modern', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['custom']['mobile'] = ['childFlow' => [
            'elements' => [[
                'id' => 'mobile-image', 'type' => 'media', 'editorName' => 'Media 1', 'isHidden' => true,
                'items' => [['id' => 'image-item', 'type' => 'image', 'mediaId' => '01K00000000000000000000000', 'alt' => 'Photo']],
            ]],
            'order' => [['kind' => 'element', 'id' => 'mobile-image']],
        ]];
        $appearance = $hero->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $hero->update(['content' => $content, 'appearance' => $appearance, 'is_enabled' => false]);
        $candidate = $this->candidate($project);
        $this->store()->replace($project, $candidate);
        $this->assertSame($candidate->toJson(), $this->store()->read($project)->toJson());
    }
}
