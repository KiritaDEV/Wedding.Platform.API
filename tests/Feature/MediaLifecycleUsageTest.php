<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Media\DeleteMediaAsset;
use App\Actions\Media\MediaAssetUsageChecker;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\CreateWebsiteSection;
use App\Actions\Websites\DuplicateWebsiteSection;
use App\Actions\Websites\PublishWebsite;
use App\Actions\Websites\UnpublishWebsite;
use App\Actions\Websites\UpdateWebsiteSectionContent;
use App\Enums\EventMembershipRole;
use App\Invitations\InvitationTrustCookie;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\WebsitePublicationNotReady;
use App\Website\WebsiteMediaReferenceProvider;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaLifecycleUsageTest extends TestCase
{
    use RefreshDatabase;

    private function project(?Event $event = null): Website
    {
        return app(CreateWebsiteProject::class)->handle($event ?? Event::factory()->create(['slug' => 'media-lifecycle']), 'Site', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }

    private function asset(Website $project): MediaAsset
    {
        $id = (string) Str::ulid();
        $asset = MediaAsset::query()->create(['event_id' => $project->event_id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 5,
            'content_hash' => hash('sha256', $id), 'storage_disk' => 'media-lifecycle', 'original_path' => $id.'/original.jpg']);
        Storage::disk('media-lifecycle')->put($asset->original_path, 'original');
        foreach (['web', 'thumbnail'] as $key) {
            $asset->variants()->create(['variant_key' => $key, 'mime_type' => 'image/webp', 'width' => 100, 'height' => 100,
                'size_bytes' => 5, 'storage_disk' => 'media-lifecycle', 'storage_path' => $id.'/'.$key.'.webp']);
            Storage::disk('media-lifecycle')->put($id.'/'.$key.'.webp', $key);
        }

        return $asset;
    }

    private function mediaBlock(string $id, string $assetId, bool $hidden = false): array
    {
        return ['id' => $id, 'type' => 'media', 'editorName' => 'Media', 'isHidden' => $hidden,
            'items' => [['id' => $id.'-item', 'type' => 'image', 'mediaId' => $assetId, 'alt' => 'Photo']]];
    }

    private function heroReference(Website $project, MediaAsset $asset): void
    {
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$this->mediaBlock('hero-media', $asset->id)], 'order' => [['kind' => 'element', 'id' => 'hero-media']]];
        app(UpdateWebsiteSectionContent::class)->handle($hero, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media-lifecycle');
    }

    public function test_library_and_delete_feedback_track_drafts_and_active_or_inactive_snapshots_and_release_on_republish(): void
    {
        $project = $this->project();
        $owner = User::factory()->create();
        $project->event->memberships()->create(['user_id' => $owner->id, 'role' => EventMembershipRole::Owner]);
        $asset = $this->asset($project);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $original = $hero->content;
        $this->heroReference($project, $asset);
        $usage = app(MediaAssetUsageChecker::class);
        $this->assertSame(['draft'], array_column($usage->usageFor($asset)->references, 'source'));
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertSame(['draft', 'publishedSnapshot'], array_column($usage->usageFor($asset)->references, 'source'));
        $url = "/api/events/{$project->event_id}/media/{$asset->id}";
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(2, 'data.usage.references')
            ->assertJsonPath('data.usage.references.1.source', 'publishedSnapshot')->assertDontSee('storage_path');
        app(UpdateWebsiteSectionContent::class)->handle($hero, $original);
        $other = $this->project($project->event);
        app(PublishWebsite::class)->handle($project->event, $other);
        app(UnpublishWebsite::class)->handle($project->event);
        $this->actingAs($owner)->getJson("/api/events/{$project->event_id}/media")->assertOk()
            ->assertJsonPath('data.0.usage.references.0.source', 'publishedSnapshot');
        $this->actingAs($owner)->deleteJson($url)->assertConflict()->assertJsonPath('code', 'media_asset_in_use')
            ->assertJsonPath('usage.references.0.websiteProjectId', $project->id)
            ->assertJsonPath('usage.references.0.source', 'publishedSnapshot');
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertFalse($usage->isUsed($asset));
        $this->actingAs($owner)->deleteJson($url)->assertNoContent();
    }

    public function test_hidden_disabled_and_custom_compositions_remain_retained_after_draft_removal(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $original = $hero->content;
        $content = $original;
        $content['compositions']['custom']['mobile'] = ['childFlow' => ['elements' => [
            ['id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group', 'isHidden' => true,
                'backgroundMedia' => ['assetId' => $asset->id], 'children' => [
                    ['id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Nested', 'backgroundMedia' => ['assetId' => $asset->id],
                        'children' => [$this->mediaBlock('mobile-media', $asset->id)]]]],
        ], 'order' => [['kind' => 'element', 'id' => 'outer']]]];
        $appearance = $hero->appearance;
        $appearance['shared']['backgroundMedia'] = ['assetId' => $asset->id];
        $appearance['custom']['mobile'] = $appearance['shared'];
        $hero->update(['content' => $content, 'appearance' => $appearance, 'is_enabled' => false]);
        app(PublishWebsite::class)->handle($project->event, $project);
        $usage = app(MediaAssetUsageChecker::class)->usageFor($asset);
        $this->assertCount(10, $usage->references); // Two Hero branches + three custom Group/Media references per source.
        $this->get("/api/public/events/media-lifecycle/media/{$asset->id}/web")->assertNotFound();
        $hero->delete();
        $usage = app(MediaAssetUsageChecker::class)->usageFor($asset);
        $this->assertCount(5, $usage->references);
        $this->assertSame(['publishedSnapshot'], array_values(array_unique(array_column($usage->references, 'source'))));
    }

    public function test_image_replacement_keeps_old_snapshot_image_until_republish_without_copying_files(): void
    {
        $project = $this->project();
        $a = $this->asset($project);
        $b = $this->asset($project);
        $this->heroReference($project, $a);
        app(PublishWebsite::class)->handle($project->event, $project);
        $files = Storage::disk('media-lifecycle')->allFiles();
        $this->heroReference($project, $b);
        $usage = app(MediaAssetUsageChecker::class);
        $this->assertSame('publishedSnapshot', $usage->usageFor($a)->references[0]['source']);
        $this->assertSame('draft', $usage->usageFor($b)->references[0]['source']);
        $this->get("/api/public/events/media-lifecycle/media/{$a->id}/web")->assertOk();
        $this->get("/api/public/events/media-lifecycle/media/{$b->id}/web")->assertNotFound();
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertFalse($usage->isUsed($a));
        $this->get("/api/public/events/media-lifecycle/media/{$a->id}/web")->assertNotFound();
        $this->get("/api/public/events/media-lifecycle/media/{$b->id}/web")->assertOk();
        $this->assertSame($files, Storage::disk('media-lifecycle')->allFiles());
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertDatabaseCount('media_variants', 4);
    }

    public function test_duplicated_sections_and_independent_projects_retain_same_asset_with_new_block_identities(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $section = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $content = $section->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$this->mediaBlock('source', $asset->id)], 'order' => [['kind' => 'element', 'id' => 'source']]];
        app(UpdateWebsiteSectionContent::class)->handle($section, $content);
        $duplicate = app(DuplicateWebsiteSection::class)->handle($section);
        $this->assertNotSame('source', $duplicate->content['compositions']['shared']['childFlow']['elements'][0]['id']);
        $other = $this->project($project->event);
        $this->heroReference($other, $asset);
        $usage = app(MediaAssetUsageChecker::class)->usageFor($asset);
        $this->assertCount(3, $usage->references);
        $this->assertCount(2, array_unique(array_column($usage->references, 'websiteProjectId')));
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_missing_files_records_and_unsafe_content_types_fail_closed_for_editor_public_and_trusted_private(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $this->heroReference($project, $asset);
        app(PublishWebsite::class)->handle($project->event, $project);
        $owner = User::factory()->create();
        $project->event->memberships()->create(['user_id' => $owner->id, 'role' => EventMembershipRole::Owner]);
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
        $open = $this->postJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $name = app(InvitationTrustCookie::class)->name($invitation->fresh()->currentBrowserCredential);
        $this->withUnencryptedCookie($name, $open->getCookie($name, false)->getValue());
        $urls = ["/api/events/{$project->event_id}/media/{$asset->id}/variants/web",
            "/api/public/events/media-lifecycle/media/{$asset->id}/web", route('private-invitations.media.web', ['token' => $token, 'asset' => $asset->id])];
        $this->actingAs($owner);
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        }
        $web = $asset->variants()->where('variant_key', 'web')->sole();
        Storage::disk('media-lifecycle')->delete($web->storage_path);
        foreach ($urls as $url) {
            $this->getJson($url)->assertNotFound()->assertDontSee($web->storage_path);
        }
        $this->get("/api/events/{$project->event_id}/media/{$asset->id}/variants/thumbnail")->assertOk();
        Storage::disk('media-lifecycle')->put($web->storage_path, 'web');
        $web->update(['mime_type' => 'text/html']);
        $before = $project->fresh()->getRawOriginal('published_snapshot');
        try {
            app(PublishWebsite::class)->handle($project->event, $project);
            $this->fail('Unsafe variant was published.');
        } catch (WebsitePublicationNotReady $exception) {
            $this->assertSame('media_web_variant_invalid', $exception->diagnostics[0]['code']);
            $this->assertSame($before, $project->fresh()->getRawOriginal('published_snapshot'));
        }
        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }
        $web->delete();
        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->getJson("/api/events/{$project->event_id}/media/{$asset->id}")->assertOk()->assertJsonMissingPath('data.variants.web');
        $this->get("/api/events/{$project->event_id}/media/{$asset->id}/variants/original")->assertNotFound();
        $asset->variants()->delete();
        $empty = $this->getJson("/api/events/{$project->event_id}/media/{$asset->id}")->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($empty->getContent())->data->variants);
    }

    public function test_malformed_retained_snapshot_blocks_usage_and_deletion_without_touching_files(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $owner = User::factory()->create();
        $project->event->memberships()->create(['user_id' => $owner->id, 'role' => EventMembershipRole::Owner]);
        DB::table('websites')->where('id', $project->id)->update(['published_snapshot' => '{}']);
        $this->actingAs($owner)->getJson("/api/events/{$project->event_id}/media")->assertUnprocessable();
        $this->actingAs($owner)->deleteJson("/api/events/{$project->event_id}/media/{$asset->id}")->assertUnprocessable();
        try {
            app(DeleteMediaAsset::class)->handle($asset);
            $this->fail('Malformed retention was ignored.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Republish', $exception->getMessage());
            $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
            $this->assertTrue(Storage::disk('media-lifecycle')->exists($asset->original_path));
        }
    }

    public function test_deleting_an_inactive_project_releases_its_draft_and_retained_snapshot_references(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $this->heroReference($project, $asset);
        app(PublishWebsite::class)->handle($project->event, $project);
        $active = $this->project($project->event);
        app(PublishWebsite::class)->handle($project->event, $active);
        $this->assertTrue(app(MediaAssetUsageChecker::class)->isUsed($asset));
        $project->delete();
        $this->assertFalse(app(MediaAssetUsageChecker::class)->isUsed($asset));
    }

    public function test_canonical_extractor_covers_people_gallery_and_tagged_future_extensions_without_duplicate_group_traversal(): void
    {
        $provider = new class implements WebsiteMediaReferenceProvider
        {
            public function section(string $type, array $content, array $appearance): array
            {
                return [];
            }

            public function element(array $element, string $compositionScope): array
            {
                return ($element['type'] ?? null) === 'future-test'
                    ? [['mediaId' => 'future-image', 'reference' => ['type' => 'sectionMedia', 'compositionScope' => $compositionScope, 'elementId' => $element['id']]]]
                    : [];
            }
        };
        $this->app->instance('test.media-provider', $provider);
        $this->app->tag('test.media-provider', WebsiteMediaReferenceProvider::class);
        $flow = ['elements' => [['id' => 'group', 'type' => 'compositionGroup', 'children' => [
            ['id' => 'people', 'type' => 'people', 'groups' => [['id' => 'family', 'name' => 'Family', 'people' => [['id' => 'person', 'name' => 'Name', 'media' => ['assetId' => 'person-image']]]]]],
            ['id' => 'future', 'type' => 'future-test'],
        ]]]];
        $content = ['semantic' => ['items' => [['id' => 'gallery-item', 'mediaId' => 'gallery-image']]],
            'compositions' => ['shared' => ['childFlow' => $flow], 'custom' => ['mobile' => ['childFlow' => $flow]]]];
        $refs = app(WebsiteSectionMediaReferenceExtractor::class)->extract('section', 'gallery', $content);
        $this->assertSame(['gallery-image', 'person-image', 'future-image', 'person-image', 'future-image'], array_column($refs, 'mediaId'));
        $this->assertSame('custom/mobile', $refs[4]['reference']['compositionScope']);
    }

    public function test_reference_creation_after_serialized_deletion_is_rejected(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        app(DeleteMediaAsset::class)->handle($asset);
        $this->expectException(ValidationException::class);
        $this->heroReference($project, $asset);
        // This checks serialized operations; SQLite does not verify competing MySQL row locks.
    }
}
