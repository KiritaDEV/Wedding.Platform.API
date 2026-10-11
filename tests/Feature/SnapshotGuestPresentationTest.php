<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\PublishWebsite;
use App\Actions\Websites\UnpublishWebsite;
use App\Enums\EventMembershipRole;
use App\Invitations\InvitationTrustCookie;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\PublishedWebsiteSnapshotStore;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SnapshotGuestPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function project(?Event $event = null): Website
    {
        $event ??= Event::factory()->create(['slug' => 'snapshot-site', 'event_date' => '2027-02-03', 'start_time' => '14:30', 'time_zone' => 'Asia/Manila']);

        return app(CreateWebsiteProject::class)->handle($event, 'Snapshot Site', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }

    private function publish(Website $project): void
    {
        app(PublishWebsite::class)->handle($project->event, $project);
    }

    private function site(): array
    {
        return $this->getJson('/api/public/events/snapshot-site/site')->assertOk()->json('data');
    }

    private function text(string $id, string $text, bool $hidden = false): array
    {
        return ['id' => $id, 'type' => 'text', 'editorName' => 'Text', 'isHidden' => $hidden,
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $text]]]]]];
    }

    public function test_saved_draft_content_design_appearance_devices_and_event_changes_stay_private_until_republish(): void
    {
        $project = $this->project();
        $this->publish($project);
        $a = $this->site();
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $privateA = $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data');
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$this->text('draft-b', 'Draft B')], 'order' => [['kind' => 'element', 'id' => 'draft-b']]];
        $content['compositions']['custom']['mobile'] = ['childFlow' => ['elements' => [$this->text('mobile-b', 'Mobile B')], 'order' => [['kind' => 'element', 'id' => 'mobile-b']]]];
        $appearance = $hero->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $hero->update(['content' => $content, 'appearance' => $appearance]);
        $design = $project->design_settings;
        $design['customColors'][] = ['id' => 'project-color-'.(string) Str::ulid(), 'value' => '#123456'];
        $project->update(['design_settings' => $design]);
        $project->event->update(['name' => 'Event B', 'event_date' => '2028-04-05', 'start_time' => '10:00', 'time_zone' => 'UTC']);
        $this->assertSame($a, $this->site());
        $privateB = $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data');
        $this->assertSame($privateA['event'], $privateB['event']);
        $this->assertSame($privateA['website'], $privateB['website']);

        $owner = User::factory()->create();
        $project->event->memberships()->create(['user_id' => $owner->id, 'role' => EventMembershipRole::Owner]);
        $this->actingAs($owner)->getJson("/api/events/{$project->event_id}/websites/{$project->id}")
            ->assertOk()->assertSee('Draft B')->assertSee('Mobile B');
        $this->publish($project);
        $b = $this->site();
        $this->assertSame('Event B', $b['event']['name']);
        $this->assertSame('2028-04-05', $b['event']['eventDate']);
        $this->assertSame('10:00', $b['event']['startTime']);
        $this->assertSame('UTC', $b['event']['timeZone']);
        $this->assertSame('2028-04-05T10:00:00Z', $b['event']['startsAtUtc']);
        $this->assertStringContainsString('Mobile B', json_encode($b));
        $this->assertSame($design, $b['website']['designSettings']);
        $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()
            ->assertJsonPath('data.event.name', 'Event B')->assertSee('Mobile B');
    }

    public function test_draft_section_reorder_disable_and_delete_do_not_change_published_sections(): void
    {
        $project = $this->project();
        $this->publish($project);
        $a = $this->site();
        $project->sections()->where('type', 'hero')->update(['sort_order' => 999, 'is_enabled' => false]);
        $project->sections()->where('type', 'gallery')->delete();
        $this->assertSame($a, $this->site());
        $this->publish($project);
        $types = array_column($this->site()['website']['sections'], 'type');
        $this->assertNotContains('hero', $types);
        $this->assertNotContains('gallery', $types);
    }

    public function test_missing_malformed_identity_and_composition_references_fail_closed_for_both_audiences(): void
    {
        $project = $this->project();
        $this->publish($project);
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $valid = json_decode($project->fresh()->getRawOriginal('published_snapshot'), true);
        $badIdentity = $valid;
        $badIdentity['project']['id'] = (string) Str::ulid();
        $badReference = $valid;
        $badReference['website']['sections'][0]['content']['compositions']['shared']['childFlow']['order'][] = ['kind' => 'element', 'id' => 'missing'];
        $badShared = $valid;
        $badShared['sharedContentDefinitions'] = [['id' => 'future']];
        foreach ([null, '{}', json_encode($badIdentity), json_encode($badReference), json_encode($badShared)] as $payload) {
            DB::table('websites')->where('id', $project->id)->update(['published_snapshot' => $payload]);
            $this->getJson('/api/public/events/snapshot-site/site')->assertNotFound()->assertDontSee('Snapshot Site');
            $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertNotFound();
        }
    }

    public function test_hidden_root_groups_nested_children_and_custom_content_are_removed_without_mutating_snapshot(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->sole();
        $group = ['id' => 'visible-group', 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [
            $this->text('visible', 'VISIBLE'), $this->text('nested-hidden', 'SECRET NESTED', true),
            ['id' => 'hidden-group', 'type' => 'compositionGroup', 'editorName' => 'Hidden Group', 'isHidden' => true,
                'children' => [$this->text('unreachable', 'SECRET UNREACHABLE')]],
        ]];
        $content = $hero->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$group, $this->text('hidden-root', 'SECRET ROOT', true)],
            'order' => [['kind' => 'element', 'id' => 'visible-group'], ['kind' => 'element', 'id' => 'hidden-root']]];
        $content['compositions']['custom']['mobile'] = ['childFlow' => ['elements' => [$this->text('hidden-mobile', 'SECRET MOBILE', true)], 'order' => [['kind' => 'element', 'id' => 'hidden-mobile']]]];
        $appearance = $hero->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];
        $hero->update(['content' => $content, 'appearance' => $appearance]);
        $this->publish($project);
        $stored = app(PublishedWebsiteSnapshotStore::class)->read($project)->toJson();
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        $public = $this->getJson('/api/public/events/snapshot-site/site')->assertOk()->assertSee('VISIBLE')->assertDontSee('SECRET');
        $private = $this->draftPostJson('/api/private-invitations/site', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk()->assertDontSee('SECRET');
        if ($path = getenv('KIRITA_SNAPSHOT_HYDRATION_FIXTURE')) {
            $privateData = json_decode($private->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
            file_put_contents($path, json_encode(['public' => json_decode($public->getContent(), false, 512, JSON_THROW_ON_ERROR),
                'privateInvitation' => $privateData->privateInvitation,
                'rsvpSections' => array_values(array_filter($privateData->website->sections, fn ($section) => $section->type === 'rsvp'))], JSON_THROW_ON_ERROR));
        }
        $flow = collect($public->json('data.website.sections'))->firstWhere('id', $hero->id)['content']['compositions'];
        $this->assertSame([['kind' => 'element', 'id' => 'visible-group']], $flow['shared']['childFlow']['order']);
        $this->assertSame([], $flow['custom']['mobile']['childFlow']['elements']);
        $this->assertSame([], $flow['custom']['mobile']['childFlow']['order']);
        $this->assertStringContainsString('SECRET UNREACHABLE', $stored);
        $this->assertSame($stored, app(PublishedWebsiteSnapshotStore::class)->read($project)->toJson());
    }

    public function test_failed_republish_preserves_guest_payload_and_unpublish_removes_access(): void
    {
        $project = $this->project();
        $this->publish($project);
        $a = $this->site();
        $project->sections()->where('type', 'hero')->update(['content' => json_encode(['broken' => true])]);
        try {
            $this->publish($project);
            $this->fail('Invalid draft was published.');
        } catch (ValidationException) {
            $this->assertSame($a, $this->site());
        }
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        app(UnpublishWebsite::class)->handle($project->event);
        $this->assertSame('unpublished', $this->site()['status']);
        $this->draftPostJson('/api/private-invitations/site', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.website', null);
    }

    private function asset(Website $project): MediaAsset
    {
        $id = (string) Str::ulid();
        Storage::disk('snapshot-media')->put($id.'.webp', 'image');
        $asset = MediaAsset::query()->create(['event_id' => $project->event_id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 100, 'height' => 100, 'size_bytes' => 5,
            'content_hash' => hash('sha256', $id), 'storage_disk' => 'snapshot-media', 'original_path' => $id.'.jpg']);
        $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 100, 'height' => 100,
            'size_bytes' => 5, 'storage_disk' => 'snapshot-media', 'storage_path' => $id.'.webp']);

        return $asset;
    }

    private function mediaBlock(string $id, MediaAsset $asset, bool $hidden = false): array
    {
        return ['id' => $id, 'type' => 'media', 'editorName' => 'Media', 'isHidden' => $hidden,
            'items' => [['id' => $id.'-item', 'type' => 'image', 'mediaId' => $asset->id, 'alt' => 'Photo']]];
    }

    public function test_snapshot_media_bridge_preserves_public_private_hidden_disabled_and_inactive_boundaries(): void
    {
        Storage::fake('snapshot-media');
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
        $project = $this->project();
        [$public, $hidden, $private, $inactive, $disabled] = array_map(fn () => $this->asset($project), range(1, 5));
        $hero = $project->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$this->mediaBlock('public', $public),
            ['id' => 'hidden-group', 'type' => 'compositionGroup', 'editorName' => 'Group', 'isHidden' => true, 'children' => [$this->mediaBlock('hidden', $hidden)]]],
            'order' => [['kind' => 'element', 'id' => 'public'], ['kind' => 'element', 'id' => 'hidden-group']]];
        $hero->update(['content' => $content]);
        $rsvp = $project->sections()->where('type', 'rsvp')->sole();
        $content = $rsvp->content;
        $content['compositions']['shared']['childFlow']['elements'][] = $this->mediaBlock('private', $private);
        $content['compositions']['shared']['childFlow']['order'][] = ['kind' => 'element', 'id' => 'private'];
        $rsvp->update(['content' => $content]);
        $gallery = $project->sections()->where('type', 'gallery')->sole();
        $content = $gallery->content;
        $content['semantic']['items'] = [['id' => 'disabled-item', 'type' => 'image', 'mediaId' => $disabled->id]];
        $gallery->update(['content' => $content, 'is_enabled' => false]);
        $this->publish($project);
        $other = $this->project($project->event);
        $otherHero = $other->sections()->where('type', 'hero')->sole();
        $content = $otherHero->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [$this->mediaBlock('inactive', $inactive)], 'order' => [['kind' => 'element', 'id' => 'inactive']]];
        $otherHero->update(['content' => $content]);
        $this->publish($other);
        $this->publish($project);
        $hero->delete(); // Snapshot-only image references must survive draft removal.
        $this->assertSame([$public->id], array_keys($this->site()['website']['media']));
        $this->get("/api/public/events/snapshot-site/media/{$public->id}/web")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        foreach ([$hidden, $private, $inactive, $disabled] as $asset) {
            $this->get("/api/public/events/snapshot-site/media/{$asset->id}/web")->assertNotFound();
        }
        $invitation = app(CreateInvitation::class)->handle($project->event, [['first_name' => 'Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $url = route('private-invitations.media.web', ['token' => $token, 'asset' => $private->id]);
        $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()
            ->assertJsonPath('data.website.media.'.$private->id.'.web.url', $url)->assertJsonPath('data.privateInvitation.rsvp', null);
        $this->get($url)->assertNotFound();
        $open = $this->draftPostJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $name = app(InvitationTrustCookie::class)->name($invitation->fresh()->currentBrowserCredential);
        $this->withUnencryptedCookie($name, $open->getCookie($name, false)->getValue());
        $this->get($url)->assertOk();
        $this->withUnencryptedCookie($name, 'invalid-secret')->get($url)->assertNotFound();
        $this->withUnencryptedCookie($name, $open->getCookie($name, false)->getValue());
        foreach ([$hidden, $inactive, $disabled] as $asset) {
            $this->get(route('private-invitations.media.web', ['token' => $token, 'asset' => $asset->id]))->assertNotFound();
        }
        DB::table('websites')->where('id', $project->id)->update(['published_snapshot' => '{}']);
        $this->get($url)->assertNotFound();
        $this->get("/api/public/events/snapshot-site/media/{$public->id}/web")->assertNotFound();
        app(UnpublishWebsite::class)->handle($project->event);
        $this->get($url)->assertNotFound();
        $this->get("/api/public/events/snapshot-site/media/{$public->id}/web")->assertNotFound();
    }

    public function test_guest_resolution_does_not_query_mutable_sections(): void
    {
        $project = $this->project();
        $this->publish($project);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->site();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('website_sections', $query['query']);
        }
    }
}
