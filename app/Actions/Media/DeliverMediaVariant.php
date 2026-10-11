<?php

namespace App\Actions\Media;

use App\Models\MediaVariant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Call only after the existing editor or guest audience authorization. */
final class DeliverMediaVariant
{
    public function handle(MediaVariant $variant): StreamedResponse
    {
        abort_unless(in_array($variant->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        try {
            $disk = Storage::disk($variant->storage_disk);
            $exists = $disk->exists($variant->storage_path);
        } catch (Throwable) {
            abort(503, 'Image storage is temporarily unavailable.');
        }
        abort_unless($exists, 404);

        return $disk->response($variant->storage_path, null, [
            'Content-Type' => $variant->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
