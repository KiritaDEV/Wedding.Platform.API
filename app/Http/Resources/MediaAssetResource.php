<?php

namespace App\Http\Resources;

use App\Actions\Media\MediaAssetUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'originalFilename' => $this->original_filename,
            'mimeType' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'sizeBytes' => $this->size_bytes,
            'createdAt' => $this->created_at?->toISOString(),
            'variants' => $this->variants->mapWithKeys(fn ($variant): array => [
                $variant->variant_key => [
                    'width' => $variant->width,
                    'height' => $variant->height,
                    'url' => route('events.media.variants.show', [
                        'event' => $this->event_id,
                        'asset' => $this->id,
                        'variant' => $variant->variant_key,
                    ]),
                ],
            ])->all(),
            'usage' => ($this->resource->getRelation('resolvedUsage') ?? MediaAssetUsage::empty())->toArray(),
        ];
    }
}
