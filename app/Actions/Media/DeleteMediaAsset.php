<?php

namespace App\Actions\Media;

use App\Exceptions\MediaAssetInUse;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Website\Publishing\WebsitePublicationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DeleteMediaAsset
{
    public function __construct(private readonly MediaAssetUsageChecker $usage) {}

    public function handle(MediaAsset $asset): void
    {
        $event = Event::query()->findOrFail(MediaAsset::query()->whereKey($asset->id)->value('event_id'));
        app(WebsitePublicationCoordinator::class)->forEvent($event, function (Event $lockedEvent) use ($asset): void {
            $locked = MediaAsset::query()->where('event_id', $lockedEvent->id)->orderBy('id')->lockForUpdate()->findOrFail($asset->id);
            $this->deleteLocked($locked);
        });
    }

    private function deleteLocked(MediaAsset $asset): void
    {
        $usage = $this->usage->usageFor($asset);
        if ($usage->isInUse()) {
            throw new MediaAssetInUse($usage);
        }
        $files = [$asset->storage_disk => [$asset->original_path]];
        foreach ($asset->variants()->orderBy('id')->lockForUpdate()->get() as $variant) {
            $files[$variant->storage_disk][] = $variant->storage_path;
        }
        if (! $asset->delete()) {
            throw new RuntimeException('Media deletion was cancelled.');
        }
        // Nested transactions defer this until the outermost commit; rollback keeps every file.
        // Storage is not transactional. Failed cleanup leaves an inaccessible orphan, not a broken live asset.
        DB::afterCommit(function () use ($asset, $files): void {
            foreach ($files as $disk => $paths) {
                try {
                    if (! Storage::disk($disk)->delete(array_values(array_unique($paths)))) {
                        throw new RuntimeException('Stored media cleanup failed.');
                    }
                } catch (Throwable) {
                    Log::warning('Media file cleanup failed after committed deletion.', ['assetId' => $asset->id, 'disk' => $disk]);
                }
            }
        });
    }
}
