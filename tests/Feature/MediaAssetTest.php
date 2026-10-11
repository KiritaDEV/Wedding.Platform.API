<?php

namespace Tests\Feature;

use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class MediaAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('The GD extension is required for Media processing tests.');
        }
        Storage::fake('media-test');
        config(['media.disk' => 'media-test']);
    }

    public function test_owner_upload_preserves_original_and_generates_non_upscaled_webp_variants(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);

        $response = $this->actingAs($owner)->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->image('photo.jpg', 2400, 1600),
        ]);

        $response->assertCreated()->assertJsonPath('data.mimeType', 'image/jpeg')
            ->assertJsonPath('data.width', 2400)->assertJsonPath('data.height', 1600)
            ->assertJsonPath('data.variants.thumbnail.width', 800)
            ->assertJsonPath('data.variants.thumbnail.height', 533)
            ->assertJsonPath('data.variants.web.width', 1920)
            ->assertJsonPath('data.variants.web.height', 1280)
            ->assertJsonMissingPath('data.storageDisk')->assertJsonMissingPath('data.originalPath')
            ->assertJsonMissingPath('data.contentHash');
        $asset = MediaAsset::query()->with('variants')->sole();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $asset->content_hash);
        Storage::disk('media-test')->assertExists($asset->original_path);
        foreach ($asset->variants as $variant) {
            Storage::disk('media-test')->assertExists($variant->storage_path);
            $this->assertSame('image/webp', $variant->mime_type);
        }

        $thumbnail = $asset->variants->firstWhere('variant_key', 'thumbnail');
        $web = $asset->variants->firstWhere('variant_key', 'web');
        $this->assertSame(800, $thumbnail->width);
        $this->assertSame(533, $thumbnail->height);
        $this->assertSame(1920, $web->width);
        $this->assertSame(1280, $web->height);
    }

    public function test_png_and_webp_are_authoritatively_decoded_and_accepted(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->actingAs($owner)->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->image('photo.png', 20, 10),
        ])->assertCreated()->assertJsonPath('data.mimeType', 'image/png');

        $webp = (string) (new ImageManager(new Driver))->create(20, 10)->toWebp();
        $this->actingAs($owner)->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('photo.webp', $webp),
        ])->assertCreated()->assertJsonPath('data.mimeType', 'image/webp');
    }

    public function test_variants_do_not_upscale_small_images(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);

        $this->actingAs($owner)->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->image('small.jpg', 300, 200),
        ])->assertCreated()
            ->assertJsonPath('data.variants.thumbnail.width', 300)
            ->assertJsonPath('data.variants.thumbnail.height', 200)
            ->assertJsonPath('data.variants.web.width', 300)
            ->assertJsonPath('data.variants.web.height', 200);
    }

    public function test_exact_duplicates_are_event_scoped_and_delete_allows_reupload(): void
    {
        [$ownerA, $eventA] = $this->eventFor(EventMembershipRole::Owner);
        [$ownerB, $eventB] = $this->eventFor(EventMembershipRole::Owner);
        $bytes = (string) (new ImageManager(new Driver))->create(40, 30)->toJpeg();

        $first = $this->actingAs($ownerA)->post("/api/events/{$eventA->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes),
        ])->assertCreated()->assertJsonMissingPath('data.contentHash');
        $assetId = $first->json('data.id');
        $this->assertSame(hash('sha256', $bytes), MediaAsset::findOrFail($assetId)->content_hash);

        $this->actingAs($ownerA)->withHeader('Accept', 'application/json')->post("/api/events/{$eventA->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('renamed-copy.jpg', $bytes),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This image is already in your Media Library.')
            ->assertJsonPath('errors.file.0', 'This image is already in your Media Library.');

        $this->actingAs($ownerB)->post("/api/events/{$eventB->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('same.jpg', $bytes),
        ])->assertCreated();
        $modifiedBytes = (string) (new ImageManager(new Driver))->create(41, 30)->toJpeg();
        $this->actingAs($ownerA)->post("/api/events/{$eventA->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', $modifiedBytes),
        ])->assertCreated();

        $this->actingAs($ownerA)->deleteJson("/api/events/{$eventA->id}/media/{$assetId}")->assertNoContent();
        $this->actingAs($ownerA)->post("/api/events/{$eventA->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('again.jpg', $bytes),
        ])->assertCreated();
    }

    public function test_database_enforces_event_scoped_content_hash_uniqueness(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->actingAs($owner)->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->image('source.jpg'),
        ])->assertCreated();
        $asset = MediaAsset::query()->sole();

        $this->expectException(QueryException::class);
        MediaAsset::query()->create(array_merge($asset->getAttributes(), [
            'id' => (string) Str::ulid(),
            'original_path' => 'duplicate/original.jpg',
        ]));
    }

    public function test_admin_and_super_admin_can_upload_but_unrelated_and_unauthenticated_users_cannot(): void
    {
        [$admin, $event] = $this->eventFor(EventMembershipRole::Admin);
        $this->actingAs($admin)->post("/api/events/{$event->id}/media", ['file' => UploadedFile::fake()->image('admin.jpg', 20, 20)])->assertCreated();
        $this->actingAs(User::factory()->superAdmin()->create())->post("/api/events/{$event->id}/media", ['file' => UploadedFile::fake()->image('super.jpg', 21, 20)])->assertCreated();
        $this->actingAs(User::factory()->create())->post("/api/events/{$event->id}/media", ['file' => UploadedFile::fake()->image('other.jpg')])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/events/{$event->id}/media", ['file' => UploadedFile::fake()->image('guest.jpg')])->assertUnauthorized();
    }

    public function test_invalid_oversized_and_excessive_pixel_files_leave_no_asset_or_files(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->actingAs($owner)->withHeader('Accept', 'application/json')->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
        ])->assertUnprocessable();
        $this->actingAs($owner)->withHeader('Accept', 'application/json')->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->create('large.jpg', 20 * 1024 + 1, 'image/jpeg'),
        ])->assertUnprocessable();
        config(['media.max_pixels' => 100]);
        $this->actingAs($owner)->withHeader('Accept', 'application/json')->post("/api/events/{$event->id}/media", [
            'file' => UploadedFile::fake()->image('pixels.jpg', 11, 10),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_listing_delivery_and_deletion_are_event_scoped(): void
    {
        [$ownerA, $eventA] = $this->eventFor(EventMembershipRole::Owner);
        [$ownerB, $eventB] = $this->eventFor(EventMembershipRole::Owner);
        $eventB->memberships()->create(['user_id' => $ownerA->id, 'role' => EventMembershipRole::Admin]);
        $assetId = $this->actingAs($ownerA)->post("/api/events/{$eventA->id}/media", [
            'file' => UploadedFile::fake()->image('a.jpg', 30, 20),
        ])->assertCreated()->json('data.id');

        $this->actingAs($ownerA)->getJson("/api/events/{$eventA->id}/media")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($ownerB)->getJson("/api/events/{$eventB->id}/media")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($ownerB)->get("/api/events/{$eventA->id}/media/{$assetId}/variants/thumbnail")->assertForbidden();
        $this->actingAs($ownerA)->get("/api/events/{$eventB->id}/media/{$assetId}/variants/thumbnail")->assertNotFound();
        $this->actingAs($ownerA)->get("/api/events/{$eventA->id}/media/{$assetId}/variants/missing")->assertNotFound();
        $this->actingAs($ownerA)->get("/api/events/{$eventA->id}/media/{$assetId}/variants/thumbnail")
            ->assertOk()->assertHeader('content-type', 'image/webp');

        $directory = dirname(MediaAsset::findOrFail($assetId)->original_path);
        $this->actingAs($ownerA)->deleteJson("/api/events/{$eventA->id}/media/{$assetId}")->assertNoContent();
        $this->assertDatabaseMissing('media_assets', ['id' => $assetId]);
        $this->assertFalse(Storage::disk('media-test')->directoryExists($directory));
    }

    public function test_listing_filters_partial_trimmed_filename_and_rejects_invalid_values(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->assetFor($event, ['original_filename' => 'PS Hero.jpg']);
        $this->assetFor($event, ['original_filename' => 'reception.png', 'mime_type' => 'image/png', 'extension' => 'png']);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?search=%20hero%20")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.originalFilename', 'PS Hero.jpg');
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?search=missing")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?type=gif&orientation=wide&uploaded=year")
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'orientation', 'uploaded']);
    }

    public function test_type_orientation_and_filename_filters_compose_without_cross_event_leakage(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $otherEvent = Event::factory()->create();
        $matching = $this->assetFor($event, ['original_filename' => 'Ceremony Hero.jpg', 'width' => 1200, 'height' => 800]);
        $this->assetFor($event, ['original_filename' => 'Portrait Hero.jpg', 'width' => 800, 'height' => 1200]);
        $this->assetFor($event, ['original_filename' => 'Square.png', 'mime_type' => 'image/png', 'extension' => 'png', 'width' => 500, 'height' => 500]);
        $this->assetFor($event, ['original_filename' => 'Wide.webp', 'mime_type' => 'image/webp', 'extension' => 'webp', 'width' => 900, 'height' => 400]);
        $this->assetFor($otherEvent, ['original_filename' => 'Ceremony Hero.jpg', 'width' => 1200, 'height' => 800]);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?search=ceremony&type=jpeg&orientation=landscape")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?type=png&orientation=square")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mimeType', 'image/png');
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?type=webp")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mimeType', 'image/webp');
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?orientation=portrait")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_uploaded_date_filters_pagination_and_newest_first_ordering(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00 UTC');
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $today = $this->assetFor($event, ['original_filename' => 'today.jpg', 'created_at' => now(), 'updated_at' => now()]);
        $this->assetFor($event, ['original_filename' => 'six-days.jpg', 'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6)]);
        $this->assetFor($event, ['original_filename' => 'twenty-days.jpg', 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
        $this->assetFor($event, ['original_filename' => 'old.jpg', 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?uploaded=today")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $today->id);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?uploaded=7d")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?uploaded=30d")->assertOk()->assertJsonCount(3, 'data');

        for ($index = 0; $index < 22; $index++) {
            $this->assetFor($event, ['original_filename' => "page-{$index}.jpg", 'created_at' => now()->subMinutes($index + 2), 'updated_at' => now()->subMinutes($index + 2)]);
        }
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?type=jpeg&page=1")
            ->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $today->id);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/media?type=jpeg&page=2")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        Carbon::setTestNow();
    }

    private function assetFor(Event $event, array $attributes = []): MediaAsset
    {
        return MediaAsset::query()->create(array_merge([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'original_filename' => 'image.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 100,
            'content_hash' => hash('sha256', (string) Str::ulid()),
            'storage_disk' => 'media-test',
            'original_path' => 'fixtures/'.Str::ulid().'/original.jpg',
        ], $attributes));
    }

    private function eventFor(EventMembershipRole $role): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $event->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return [$user, $event];
    }
}
