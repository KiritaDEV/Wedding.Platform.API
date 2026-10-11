<?php

namespace App\Website\Publishing;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WebsitePublicationMediaRule implements WebsitePublicationRule
{
    public function __construct(private readonly WebsiteSectionMediaReferences $references) {}

    /** Must run inside the Event-first publication transaction. Guest rendering requires the web variant. */
    public function diagnostics(PublishedWebsiteSnapshot $snapshot): array
    {
        $data = json_decode($snapshot->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $ids = $data['mediaAssetIds'];
        if ($ids === []) {
            return [];
        }
        $assets = MediaAsset::query()->where('event_id', $data['event']['id'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = MediaVariant::query()->whereIn('media_asset_id', $assets->keys())->where('variant_key', 'web')->orderBy('id')->lockForUpdate()->get()->keyBy('media_asset_id');
        $problems = [];
        foreach ($ids as $id) {
            if (! $assets->has($id)) {
                $problems[$id] = ['code' => 'media_unavailable', 'message' => 'Select an existing image owned by this Event.'];
            } elseif (! in_array($assets[$id]->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $problems[$id] = ['code' => 'media_invalid', 'message' => 'The referenced asset is not a supported image.'];
            } elseif (! $variants->has($id)) {
                $problems[$id] = ['code' => 'media_web_variant_missing', 'message' => 'The image has no web variant.'];
            } else {
                $variant = $variants[$id];
                if (! in_array($variant->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    $problems[$id] = ['code' => 'media_web_variant_invalid', 'message' => 'The web variant is not a supported image.'];

                    continue;
                }
                try {
                    if (! Storage::disk($variant->storage_disk)->exists($variant->storage_path)) {
                        $problems[$id] = ['code' => 'media_web_file_missing', 'message' => 'The stored web image is unavailable.'];
                    }
                } catch (Throwable) {
                    $problems[$id] = ['code' => 'media_storage_unavailable', 'message' => 'Image storage could not be verified.'];
                }
            }
        }
        $diagnostics = [];
        foreach ($data['website']['sections'] as $section) {
            $section['content']['sharedContentDefinitions'] = array_values(array_filter($data['sharedContentDefinitions'], fn (array $definition): bool => $definition['sectionId'] === $section['id']));
            foreach ($this->references->extract($section['type'], $section['content'], $section['appearance']) as $reference) {
                if (isset($problems[$reference['assetId']])) {
                    $diagnostics[] = [
                        ...$problems[$reference['assetId']],
                        'projectId' => $data['project']['id'], 'sectionId' => $section['id'],
                        'blockId' => $reference['context']['elementId'] ?? null,
                        'assetId' => $reference['assetId'], 'context' => $reference['context'] ?? [],
                    ];
                }
            }
        }

        return $diagnostics;
    }
}
