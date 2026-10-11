<?php

namespace App\Actions\Media;

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

final class UploadMediaAsset
{
    public function handle(Event $event, User $user, UploadedFile $file): MediaAsset
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Media image processing requires the PHP GD extension.');
        }

        $contentHash = hash_file('sha256', $file->getPathname());
        if ($contentHash === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }
        $this->rejectDuplicate($event, $contentHash);

        try {
            $image = (new ImageManager(new Driver))->read($file->getPathname())->orient();
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The file must be a valid JPEG, PNG, or WebP image.']);
        }

        $mimeType = $image->origin()->mediaType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['file' => 'Only JPEG, PNG, and WebP images are supported.']),
        };
        $width = $image->width();
        $height = $image->height();
        if ($width * $height > config('media.max_pixels')) {
            throw ValidationException::withMessages(['file' => 'The image exceeds the 50 megapixel limit.']);
        }

        $assetId = (string) Str::ulid();
        $diskName = config('media.disk');
        $directory = "events/{$event->getKey()}/media/{$assetId}";
        $disk = Storage::disk($diskName);
        $originalPath = "{$directory}/original.{$extension}";
        $written = [];

        try {
            $originalContents = file_get_contents($file->getPathname());
            if ($originalContents === false || ! $disk->put($originalPath, $originalContents)) {
                throw new RuntimeException('Unable to store the original image.');
            }
            $written[] = $originalPath;

            $variantRows = [];
            foreach (['thumbnail' => config('media.thumbnail_max_edge'), 'web' => config('media.web_max_edge')] as $key => $maxEdge) {
                $variantImage = clone $image;
                $variantImage->scaleDown(width: $maxEdge, height: $maxEdge);
                $encoded = $variantImage->toWebp(quality: config('media.webp_quality'));
                $path = "{$directory}/{$key}.webp";
                if (! $disk->put($path, (string) $encoded)) {
                    throw new RuntimeException("Unable to store the {$key} image variant.");
                }
                $written[] = $path;
                $variantRows[] = [
                    'id' => (string) Str::ulid(),
                    'variant_key' => $key,
                    'mime_type' => 'image/webp',
                    'width' => $variantImage->width(),
                    'height' => $variantImage->height(),
                    'size_bytes' => $encoded->size(),
                    'storage_disk' => $diskName,
                    'storage_path' => $path,
                ];
            }

            $asset = DB::transaction(function () use ($assetId, $event, $user, $file, $mimeType, $extension, $width, $height, $contentHash, $diskName, $originalPath, $variantRows): MediaAsset {
                $asset = MediaAsset::query()->create([
                    'id' => $assetId,
                    'event_id' => $event->getKey(),
                    'created_by_user_id' => $user->getKey(),
                    'original_filename' => basename($file->getClientOriginalName()),
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'width' => $width,
                    'height' => $height,
                    'size_bytes' => $file->getSize(),
                    'content_hash' => $contentHash,
                    'storage_disk' => $diskName,
                    'original_path' => $originalPath,
                ]);
                $asset->variants()->createMany($variantRows);

                return $asset;
            });

            return $asset->load('variants');
        } catch (Throwable $exception) {
            $disk->delete($written);
            if ($exception instanceof QueryException && $event->mediaAssets()->where('content_hash', $contentHash)->exists()) {
                $this->duplicateValidationException();
            }
            throw $exception;
        }
    }

    private function rejectDuplicate(Event $event, string $contentHash): void
    {
        if ($event->mediaAssets()->where('content_hash', $contentHash)->exists()) {
            $this->duplicateValidationException();
        }
    }

    private function duplicateValidationException(): never
    {
        throw ValidationException::withMessages([
            'file' => 'This image is already in your Media Library.',
        ]);
    }
}
