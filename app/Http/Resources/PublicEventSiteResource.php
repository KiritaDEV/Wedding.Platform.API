<?php

namespace App\Http\Resources;

use App\Website\Publishing\PublishedWebsitePresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projection = app(PublishedWebsitePresentation::class);
        $snapshot = $projection->resolve($this->resource);
        if ($snapshot === null) {
            return ['status' => 'unpublished', 'event' => ['name' => $this->name, 'slug' => $this->slug], 'website' => null];
        }

        return ['status' => 'published', 'event' => $snapshot->event,
            'website' => $projection->website($snapshot, $this->resource)];
    }
}
