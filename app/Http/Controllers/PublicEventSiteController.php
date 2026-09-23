<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicEventSiteResource;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicEventSiteController extends Controller
{
    public function show(string $slug): PublicEventSiteResource
    {
        $event = Event::query()->where('slug', $slug)->with('publishedWebsite.sections.website')->firstOrFail();

        return new PublicEventSiteResource($event);
    }

    public function media(WebsiteSectionMediaReferences $references, string $slug, string $asset): StreamedResponse
    {
        $event = Event::query()->where('slug', $slug)->with('publishedWebsite.sections')->firstOrFail();
        $website = $event->publishedWebsite;
        abort_if($website === null, 404);

        $referencedIds = $website->sections->where('is_enabled', true)->where('type', '!=', 'rsvp')->flatMap(
            fn ($section) => $references->extract($section->type, $section->content, $section->appearance),
        )->pluck('assetId')->unique();
        abort_unless($referencedIds->contains($asset), 404);

        $media = MediaAsset::query()->where('event_id', $event->id)->whereKey($asset)->firstOrFail();
        $variant = $media->variants()->where('variant_key', 'web')->firstOrFail();

        return Storage::disk($variant->storage_disk)->response(
            $variant->storage_path,
            null,
            ['Content-Type' => $variant->mime_type, 'Cache-Control' => 'public, max-age=31536000, immutable'],
        );
    }
}
