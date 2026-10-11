<?php

namespace App\Actions\Media;

use App\Exceptions\MediaAssetInUse;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DeleteMediaAsset
{
    public function __construct(private readonly MediaAssetUsageChecker $usage) {}

    public function handle(MediaAsset $asset): void
    {
        $usage = $this->usage->usageFor($asset);
        if ($usage->isInUse()) {
            throw new MediaAssetInUse($usage);
        }
        $directory = dirname($asset->original_path);
        if (! Storage::disk($asset->storage_disk)->deleteDirectory($directory)) {
            throw new RuntimeException('Unable to remove the stored Media files.');
        }

        DB::transaction(fn () => $asset->delete());
    }
}
