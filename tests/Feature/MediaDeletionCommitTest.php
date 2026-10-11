<?php

namespace Tests\Feature;

use App\Actions\Media\DeleteMediaAsset;
use App\Models\Event;
use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Uses real commits in isolated SQLite, not RefreshDatabase's outer test transaction. */
class MediaDeletionCommitTest extends TestCase
{
    use DatabaseMigrations;

    private function asset(): MediaAsset
    {
        Storage::fake('media-commit');
        Storage::fake('media-variant-commit');
        $asset = MediaAsset::query()->create(['event_id' => Event::factory()->create()->id,
            'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
            'width' => 100, 'height' => 100, 'size_bytes' => 5, 'content_hash' => hash('sha256', (string) Str::ulid()),
            'storage_disk' => 'media-commit', 'original_path' => 'image/original.jpg']);
        $asset->variants()->create(['variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 100, 'height' => 100,
            'size_bytes' => 5, 'storage_disk' => 'media-variant-commit', 'storage_path' => 'image/web.webp']);
        Storage::disk('media-commit')->put('image/original.jpg', 'original');
        Storage::disk('media-variant-commit')->put('image/web.webp', 'web');

        return $asset;
    }

    private function assertFilesRemain(): void
    {
        $this->assertTrue(Storage::disk('media-commit')->exists('image/original.jpg'));
        $this->assertTrue(Storage::disk('media-variant-commit')->exists('image/web.webp'));
    }

    public function test_committed_deletion_cleans_only_recorded_files_across_disks(): void
    {
        $asset = $this->asset();
        Storage::disk('media-commit')->put('image/unrelated.jpg', 'other');
        app(DeleteMediaAsset::class)->handle($asset);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        $this->assertDatabaseMissing('media_variants', ['media_asset_id' => $asset->id]);
        $this->assertFalse(Storage::disk('media-commit')->exists('image/original.jpg'));
        $this->assertFalse(Storage::disk('media-variant-commit')->exists('image/web.webp'));
        $this->assertTrue(Storage::disk('media-commit')->exists('image/unrelated.jpg'));
    }

    public function test_nested_rollback_restores_asset_and_preserves_every_file(): void
    {
        $asset = $this->asset();
        try {
            DB::transaction(function () use ($asset): void {
                app(DeleteMediaAsset::class)->handle($asset);
                $this->assertFilesRemain();
                throw new RuntimeException('Roll back outer operation.');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
            $this->assertFilesRemain();
        }
    }

    public function test_nested_success_waits_for_outermost_commit(): void
    {
        $asset = $this->asset();
        DB::transaction(function () use ($asset): void {
            app(DeleteMediaAsset::class)->handle($asset);
            $this->assertFilesRemain();
        });
        $this->assertFalse(Storage::disk('media-commit')->exists('image/original.jpg'));
        $this->assertFalse(Storage::disk('media-variant-commit')->exists('image/web.webp'));
    }

    public function test_database_delete_exception_does_not_touch_storage(): void
    {
        $asset = $this->asset();
        DB::statement("CREATE TRIGGER reject_asset_delete BEFORE DELETE ON media_assets BEGIN SELECT RAISE(ABORT, 'delete rejected'); END");
        try {
            app(DeleteMediaAsset::class)->handle($asset);
            $this->fail('Deletion should fail.');
        } catch (QueryException) {
            $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
            $this->assertFilesRemain();
        }
    }

    public function test_cleanup_failure_is_logged_after_logical_deletion_without_breaking_other_disks(): void
    {
        $asset = $this->asset();
        $originalDisk = Storage::disk('media-commit');
        $variantDisk = Storage::disk('media-variant-commit');
        $failed = Mockery::mock();
        $failed->shouldReceive('delete')->once()->with(['image/original.jpg'])->andReturn(false);
        Storage::shouldReceive('disk')->with('media-commit')->andReturn($failed);
        Storage::shouldReceive('disk')->with('media-variant-commit')->andReturn($variantDisk);
        Log::shouldReceive('warning')->once()->with('Media file cleanup failed after committed deletion.', ['assetId' => $asset->id, 'disk' => 'media-commit']);
        app(DeleteMediaAsset::class)->handle($asset);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        $this->assertTrue($originalDisk->exists('image/original.jpg'));
        $this->assertFalse($variantDisk->exists('image/web.webp'));
    }
}
