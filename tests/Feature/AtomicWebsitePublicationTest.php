<?php

namespace Tests\Feature;

use App\Actions\Media\DeleteMediaAsset;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\PublishWebsite;
use App\Actions\Websites\UnpublishWebsite;
use App\Actions\Websites\UpdateWebsiteSectionContent;
use App\Enums\EventMembershipRole;
use App\Exceptions\MediaAssetInUse;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\PublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshotStore;
use App\Website\Publishing\WebsitePublicationCoordinator;
use App\Website\Publishing\WebsitePublicationNotReady;
use App\Website\Publishing\WebsitePublicationRule;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AtomicWebsitePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function project(?Event $event = null): Website
    {
        return app(CreateWebsiteProject::class)->handle($event ?? Event::factory()->create(), 'Site', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }

    private function publish(Website $project): void
    {
        app(PublishWebsite::class)->handle($project->event, $project);
    }

    private function publicationState(Website $project): array
    {
        $saved = $project->fresh();

        return [$saved->getRawOriginal('published_snapshot'), $saved->getRawOriginal('snapshot_published_at'), $saved->event->published_website_id];
    }

    private function media(Website $project, bool $variant = true, bool $file = true): MediaAsset
    {
        Storage::fake('publication-test');
        $asset = MediaAsset::query()->create([
            'event_id' => $project->event_id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 5,
            'content_hash' => hash('sha256', (string) Str::ulid()), 'storage_disk' => 'publication-test', 'original_path' => 'images/original.jpg',
        ]);
        if ($variant) {
            $asset->variants()->create([
                'variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 100, 'height' => 100,
                'size_bytes' => 5, 'storage_disk' => 'publication-test', 'storage_path' => 'images/web.webp',
            ]);
        }
        if ($file) {
            Storage::disk('publication-test')->put('images/web.webp', 'image');
        }

        return $asset;
    }

    private function reference(Website $project, string $id): string
    {
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow'] = [
            'elements' => [['id' => 'image-block', 'type' => 'media', 'editorName' => 'Media 1', 'isHidden' => true,
                'items' => [['id' => 'image-item', 'type' => 'image', 'mediaId' => $id, 'alt' => 'Photo']]]],
            'order' => [['kind' => 'element', 'id' => 'image-block']],
        ];
        $hero->update(['content' => $content, 'is_enabled' => false]);

        return $hero->id;
    }

    public function test_initial_publication_captures_saved_state_without_changing_draft_or_identities(): void
    {
        $project = $this->project();
        $before = $project->sections()->get()->toJson();
        $design = $project->design_settings;
        $project->name = 'Unsaved name';
        $this->publish($project);
        $saved = $project->fresh();
        $this->assertSame($project->id, $saved->event->published_website_id);
        $this->assertSame('Site', $saved->published_snapshot->project->name);
        $this->assertNotNull($saved->snapshot_published_at);
        $this->assertSame($before, $project->sections()->get()->toJson());
        $this->assertSame($design, $saved->design_settings);
    }

    public function test_same_project_republish_replaces_payload_and_timestamp_after_draft_edit(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text'] = 'Saved edit';
        app(UpdateWebsiteSectionContent::class)->handle($hero, $content);
        $project->event->update(['name' => 'Saved Event name']);
        $this->assertSame($before, $this->publicationState($project));
        $this->travel(1)->minutes();
        $this->publish($project);
        $this->travelBack();
        $after = $this->publicationState($project);
        $this->assertNotSame($before[0], $after[0]);
        $this->assertNotSame($before[1], $after[1]);
        $this->assertSame($project->id, $after[2]);
        $this->assertStringContainsString('Saved edit', $after[0]);
        $this->assertStringContainsString('Saved Event name', $after[0]);
    }

    public function test_switching_captures_target_and_retains_inactive_snapshot(): void
    {
        $first = $this->project();
        $second = $this->project($first->event);
        $this->publish($first);
        $firstState = $this->publicationState($first);
        $this->publish($second);
        $this->assertSame($firstState[0], $first->fresh()->getRawOriginal('published_snapshot'));
        $this->assertSame($firstState[1], $first->fresh()->getRawOriginal('snapshot_published_at'));
        $this->assertSame($second->id, $second->fresh()->published_snapshot->project->id);
        $this->assertSame($second->id, $first->event->fresh()->published_website_id);
    }

    public function test_invalid_saved_candidate_has_section_diagnostics_and_preserves_previous_publication(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        $rsvp = $project->sections()->where('type', 'rsvp')->sole();
        $rsvp->update(['content' => ['semantic' => ['heading' => 'Obsolete']]]);
        try {
            $this->publish($project);
            $this->fail('Invalid draft published.');
        } catch (WebsitePublicationNotReady $exception) {
            $this->assertSame('draft_invalid', $exception->diagnostics[0]['code']);
            $this->assertSame($rsvp->id, $exception->diagnostics[0]['sectionId']);
            $this->assertSame($before, $this->publicationState($project));
        }
    }

    public function test_missing_and_foreign_media_are_rejected_with_section_and_block_diagnostics(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        $foreign = $this->media($this->project());
        foreach (['01K00000000000000000000000', $foreign->id] as $id) {
            $sectionId = $this->reference($project, $id);
            try {
                $this->publish($project);
                $this->fail('Unavailable media published.');
            } catch (WebsitePublicationNotReady $exception) {
                $diagnostic = $exception->diagnostics[0];
                $this->assertSame('media_unavailable', $diagnostic['code']);
                $this->assertSame($sectionId, $diagnostic['sectionId']);
                $this->assertSame('image-block', $diagnostic['blockId']);
                $this->assertSame($before, $this->publicationState($project));
            }
        }
    }

    public function test_missing_web_record_or_file_preserves_previous_publication(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        foreach ([[false, true, 'media_web_variant_missing'], [true, false, 'media_web_file_missing']] as [$variant, $file, $code]) {
            $asset = $this->media($project, $variant, $file);
            $this->reference($project, $asset->id);
            try {
                $this->publish($project);
                $this->fail('Incomplete media published.');
            } catch (WebsitePublicationNotReady $exception) {
                $this->assertSame($code, $exception->diagnostics[0]['code']);
                $this->assertSame($before, $this->publicationState($project));
            }
        }
    }

    public function test_storage_exception_preserves_previous_snapshot_timestamp_and_pointer(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        $asset = $this->media($project);
        $this->reference($project, $asset->id);
        Storage::shouldReceive('disk')->with('publication-test')->andThrow(new RuntimeException('Storage failure'));
        try {
            $this->publish($project);
            $this->fail('Storage failure ignored.');
        } catch (WebsitePublicationNotReady $exception) {
            $this->assertSame('media_storage_unavailable', $exception->diagnostics[0]['code']);
            $this->assertSame($before, $this->publicationState($project));
        }
    }

    public function test_pointer_database_failure_rolls_back_target_snapshot_replacement(): void
    {
        $first = $this->project();
        $target = $this->project($first->event);
        $this->publish($target);
        $this->publish($first);
        $before = $this->publicationState($target);
        $target->update(['name' => 'Replacement']);
        DB::unprepared("CREATE TRIGGER reject_publication_pointer BEFORE UPDATE OF published_website_id ON events BEGIN SELECT RAISE(ABORT, 'simulated pointer failure'); END");
        try {
            $this->publish($target);
            $this->fail('Database failure ignored.');
        } catch (QueryException) {
            $this->assertSame($before, $this->publicationState($target));
        }
    }

    public function test_snapshot_save_failure_does_not_switch_active_project(): void
    {
        $first = $this->project();
        $target = $this->project($first->event);
        $this->publish($first);
        $before = $this->publicationState($target);
        Website::saving(fn (Website $saving) => $saving->id === $target->id && $saving->isDirty('published_snapshot') ? false : null);
        try {
            $this->publish($target);
            $this->fail('Cancelled snapshot save ignored.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->publicationState($target));
        }
    }

    public function test_safe_retry_replaces_one_snapshot_and_unpublish_retains_it(): void
    {
        $project = $this->project();
        $this->publish($project);
        $json = app(PublishedWebsiteSnapshotStore::class)->read($project)->toJson();
        $this->travel(1)->minutes();
        $this->publish($project);
        $this->travelBack();
        $this->assertSame($json, app(PublishedWebsiteSnapshotStore::class)->read($project)->toJson());
        $this->assertDatabaseCount('websites', 1);
        app(UnpublishWebsite::class)->handle($project->event);
        app(UnpublishWebsite::class)->handle($project->event);
        $this->assertNull($project->event->fresh()->published_website_id);
        $this->assertSame($json, app(PublishedWebsiteSnapshotStore::class)->read($project)->toJson());
    }

    public function test_tagged_future_readiness_rule_can_report_block_diagnostics(): void
    {
        $project = $this->project();
        $rule = new class implements WebsitePublicationRule
        {
            public int $calls = 0;

            public function diagnostics(PublishedWebsiteSnapshot $snapshot): array
            {
                $this->calls++;

                return [['code' => 'extension_not_ready', 'message' => 'Future rule', 'blockId' => 'future-block']];
            }
        };
        $this->app->instance('test.publication.rule', $rule);
        $this->app->tag(['test.publication.rule'], WebsitePublicationRule::class);
        try {
            $this->publish($project);
            $this->fail('Extension diagnostics ignored.');
        } catch (WebsitePublicationNotReady $exception) {
            $this->assertSame('future-block', $exception->diagnostics[0]['blockId']);
            $this->assertSame(1, $rule->calls);
            $this->assertNull($project->fresh()->published_snapshot);
            $this->assertNull($project->event->fresh()->published_website_id);
        }
    }

    public function test_nested_coordinated_mutation_and_publication_can_roll_back_together(): void
    {
        $project = $this->project();
        $this->publish($project);
        $before = $this->publicationState($project);
        $sections = $project->sections()->get()->toJson();
        try {
            app(WebsitePublicationCoordinator::class)->forProject($project, function () use ($project): void {
                $hero = $project->sections()->where('type', 'hero')->sole();
                $content = $hero->content;
                $content['compositions']['shared']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text'] = 'Edit in coordinated transaction';
                app(UpdateWebsiteSectionContent::class)->handle($hero, $content);
                $this->publish($project);
                throw new RuntimeException('Abort coordinated operation');
            });
        } catch (RuntimeException) {
            $this->assertSame($before, $this->publicationState($project));
            $this->assertSame($sections, $project->sections()->get()->toJson());
        }
    }

    public function test_snapshot_media_is_retained_after_draft_reference_is_removed_and_project_is_inactive(): void
    {
        $project = $this->project();
        $asset = $this->media($project);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $original = $hero->content;
        $this->reference($project, $asset->id);
        $this->publish($project);
        app(UpdateWebsiteSectionContent::class)->handle($hero, $original);
        $other = $this->project($project->event);
        $this->publish($other);
        try {
            app(DeleteMediaAsset::class)->handle($asset);
            $this->fail('Retained snapshot image deleted.');
        } catch (MediaAssetInUse $exception) {
            $this->assertSame('publishedSnapshot', $exception->usage->references[0]['source']);
            $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
            $this->assertTrue(Storage::disk('publication-test')->exists('images/web.webp'));
        }
        $this->publish($project);
        app(DeleteMediaAsset::class)->handle($asset);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }

    public function test_http_publication_diagnostics_preserve_authorized_project_contract(): void
    {
        $project = $this->project();
        $owner = User::factory()->create();
        $project->event->memberships()->create(['user_id' => $owner->id, 'role' => EventMembershipRole::Owner]);
        $sectionId = $this->reference($project, '01K00000000000000000000000');
        $this->actingAs($owner)->draftPostJson('/api/events/'.$project->event_id.'/websites/'.$project->id.'/publish')
            ->assertUnprocessable()->assertJsonValidationErrors('publication')
            ->assertJsonPath('diagnostics.0.code', 'media_unavailable')
            ->assertJsonPath('diagnostics.0.sectionId', $sectionId)
            ->assertJsonPath('diagnostics.0.blockId', 'image-block');
        $this->assertNull($project->fresh()->published_snapshot);
    }

    public function test_declared_lock_order_and_section_presentation_order_are_independent(): void
    {
        $project = $this->project();
        $asset = $this->media($project);
        $this->reference($project, $asset->id);
        $first = $project->sections()->first();
        $first->update(['sort_order' => 200]);
        $expectedSections = $project->sections()->pluck('id')->all();
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        // SQLite does not enforce row locks. Mark declared lock queries solely
        // to verify orchestration order; this is not a MySQL concurrency test.
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value ? '/* declared-row-lock */' : '';
            }
        });
        DB::enableQueryLog();
        try {
            $this->publish($project);
            $lockedTables = [];
            foreach (DB::getQueryLog() as $query) {
                if (str_contains($query['query'], 'declared-row-lock') && preg_match('/from "([^"]+)"/', $query['query'], $matches) === 1 && ! in_array($matches[1], $lockedTables, true)) {
                    $lockedTables[] = $matches[1];
                }
            }
            $this->assertSame(['events', 'websites', 'website_sections', 'media_assets', 'media_variants'], $lockedTables);
            $this->assertSame($expectedSections, array_map(fn ($section) => $section->id, $project->fresh()->published_snapshot->website->sections));
        } finally {
            DB::disableQueryLog();
            $connection->setQueryGrammar($originalGrammar);
        }
    }
}
