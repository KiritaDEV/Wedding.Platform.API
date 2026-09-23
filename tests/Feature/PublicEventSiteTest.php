<?php

namespace Tests\Feature;

use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\PublishWebsite;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\MediaAsset;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicEventSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_slug_is_not_found_and_unpublished_event_exposes_no_draft(): void
    {
        $this->getJson('/api/public/events/missing/site')->assertNotFound();
        $event = Event::factory()->create(['slug' => 'celebration']);
        $draft = $this->project($event, 'Secret Draft');

        $this->getJson('/api/public/events/celebration/site')->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.event.name', $event->name)
            ->assertJsonPath('data.website', null)
            ->assertJsonMissing(['id' => $draft->id])
            ->assertJsonMissing(['name' => 'Secret Draft']);
    }

    public function test_public_site_returns_only_published_project_and_omits_rsvp_and_management_data(): void
    {
        $event = Event::factory()->create(['slug' => 'our-day']);
        $first = $this->project($event, 'First Draft');
        $second = $this->project($event, 'Live Design');
        Invitation::factory()->for($event)->create(['custom_name' => 'Private Household']);
        app(PublishWebsite::class)->handle($event, $second);

        $response = $this->getJson('/api/public/events/our-day/site')->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.website.id', $second->id)
            ->assertJsonMissing(['id' => $first->id])
            ->assertJsonMissing(['name' => 'First Draft'])
            ->assertJsonMissing(['customName' => 'Private Household'])
            ->assertJsonMissingPath('data.event.membershipRole');

        $this->assertNotContains('rsvp', collect($response->json('data.website.sections'))->pluck('type')->all());

        app(PublishWebsite::class)->handle($event, $first);
        $this->getJson('/api/public/events/our-day/site')->assertJsonPath('data.website.id', $first->id);
    }

    public function test_public_media_serves_only_web_variants_referenced_by_the_published_site(): void
    {
        Storage::fake('public-site-test');
        $event = Event::factory()->create(['slug' => 'media-day']);
        $published = $this->project($event, 'Published');
        $draft = $this->project($event, 'Draft');
        $publishedAsset = $this->asset($event, 'published.webp');
        $draftAsset = $this->asset($event, 'draft.webp');
        $this->referenceHeroAsset($published, $publishedAsset->id);
        $this->referenceHeroAsset($draft, $draftAsset->id);
        app(PublishWebsite::class)->handle($event, $published);

        $payload = $this->getJson('/api/public/events/media-day/site')->assertOk();
        $payload->assertJsonPath('data.website.media.'.$publishedAsset->id.'.web.url', route('public.events.media.web', ['slug' => 'media-day', 'asset' => $publishedAsset->id]))
            ->assertJsonMissing(['id' => $draftAsset->id]);

        $this->get("/api/public/events/media-day/media/{$publishedAsset->id}/web")
            ->assertOk()->assertHeader('content-type', 'image/webp');
        $this->get("/api/public/events/media-day/media/{$draftAsset->id}/web")->assertNotFound();
        $this->getJson("/api/events/{$event->id}/media")->assertUnauthorized();
    }

    private function asset(Event $event, string $path): MediaAsset
    {
        Storage::disk('public-site-test')->put($path, 'image');
        $asset = MediaAsset::query()->create([
            'id' => (string) Str::ulid(), 'event_id' => $event->id, 'original_filename' => $path,
            'mime_type' => 'image/webp', 'extension' => 'webp', 'width' => 100, 'height' => 100,
            'size_bytes' => 5, 'content_hash' => hash('sha256', $path), 'storage_disk' => 'public-site-test',
            'original_path' => 'original-'.$path,
        ]);
        $asset->variants()->create([
            'id' => (string) Str::ulid(), 'variant_key' => 'web', 'mime_type' => 'image/webp',
            'width' => 100, 'height' => 100, 'size_bytes' => 5, 'storage_disk' => 'public-site-test',
            'storage_path' => $path,
        ]);

        return $asset;
    }

    private function referenceHeroAsset($website, string $assetId): void
    {
        $hero = $website->sections()->where('type', 'hero')->sole();
        $appearance = $hero->appearance;
        $appearance['shared']['backgroundMedia'] = ['assetId' => $assetId, 'focalPoint' => ['x' => 0.5, 'y' => 0.5], 'zoom' => 1];
        $hero->update(['appearance' => $appearance]);
    }

    private function project(Event $event, string $name)
    {
        return app(CreateWebsiteProject::class)->handle($event, $name, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }
}
