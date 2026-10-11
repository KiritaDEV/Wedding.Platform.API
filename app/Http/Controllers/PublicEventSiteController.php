<?php

namespace App\Http\Controllers;

use App\Actions\Media\DeliverMediaVariant;
use App\Http\Resources\PublicEventSiteResource;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Website\Publishing\PublishedWebsitePresentation;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicEventSiteController extends Controller
{
    public function show(string $slug): PublicEventSiteResource
    {
        $event = Event::query()->where('slug', $slug)->firstOrFail();

        return new PublicEventSiteResource($event);
    }

    public function media(PublishedWebsitePresentation $presentation, string $slug, string $asset): StreamedResponse
    {
        $event = Event::query()->where('slug', $slug)->firstOrFail();
        $snapshot = $presentation->resolve($event);
        abort_if($snapshot === null, 404);
        abort_unless(in_array($asset, $presentation->mediaIds($snapshot), true), 404);

        $media = MediaAsset::query()->where('event_id', $event->id)->whereKey($asset)->firstOrFail();
        $variant = $media->variants()->where('variant_key', 'web')->firstOrFail();

        return app(DeliverMediaVariant::class)->handle($variant);
    }
}
