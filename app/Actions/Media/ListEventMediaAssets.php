<?php

namespace App\Actions\Media;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class ListEventMediaAssets
{
    public function __construct(private readonly MediaAssetUsageChecker $usage) {}

    public function handle(Event $event, array $filters): LengthAwarePaginator
    {
        $query = $event->mediaAssets()->with('variants');
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->whereLike('original_filename', "%{$search}%", caseSensitive: false);
        }

        if (isset($filters['type'])) {
            $query->where('mime_type', match ($filters['type']) {
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            });
        }

        if (isset($filters['orientation'])) {
            match ($filters['orientation']) {
                'landscape' => $query->whereColumn('width', '>', 'height'),
                'portrait' => $query->whereColumn('height', '>', 'width'),
                'square' => $query->whereColumn('width', '=', 'height'),
            };
        }

        if (isset($filters['uploaded'])) {
            $query->where('created_at', '>=', $this->uploadedCutoff($filters['uploaded']));
        }

        $assets = $query->latest('created_at')->latest('id')->paginate(24)->withQueryString();
        $this->usage->attach($assets->getCollection());

        return $assets;
    }

    private function uploadedCutoff(string $uploaded): Carbon
    {
        return match ($uploaded) {
            'today' => now('UTC')->startOfDay(),
            '7d' => now('UTC')->subDays(7),
            '30d' => now('UTC')->subDays(30),
        };
    }
}
