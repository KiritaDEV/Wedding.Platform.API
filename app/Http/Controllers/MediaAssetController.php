<?php

namespace App\Http\Controllers;

use App\Actions\Media\DeleteMediaAsset;
use App\Actions\Media\ListEventMediaAssets;
use App\Actions\Media\MediaAssetUsageChecker;
use App\Actions\Media\UploadMediaAsset;
use App\Http\Requests\ListMediaAssetsRequest;
use App\Http\Requests\StoreMediaAssetRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Event;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    public function index(ListMediaAssetsRequest $request, ListEventMediaAssets $list, string $event): AnonymousResourceCollection
    {
        $eventModel = $this->authorizedEvent($event);

        return MediaAssetResource::collection($list->handle($eventModel, $request->validated()));
    }

    public function store(StoreMediaAssetRequest $request, UploadMediaAsset $upload, MediaAssetUsageChecker $usage, string $event): JsonResponse
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $asset = $upload->handle($eventModel, $request->user(), $request->file('file'));
        $usage->attach(collect([$asset]));

        return (new MediaAssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(MediaAssetUsageChecker $usage, string $event, string $asset): MediaAssetResource
    {
        $eventModel = $this->authorizedEvent($event);

        $mediaAsset = $this->asset($eventModel, $asset)->load('variants');
        $usage->attach(collect([$mediaAsset]));

        return new MediaAssetResource($mediaAsset);
    }

    public function destroy(DeleteMediaAsset $delete, string $event, string $asset): Response
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $delete->handle($this->asset($eventModel, $asset));

        return response()->noContent();
    }

    public function variant(string $event, string $asset, string $variant): StreamedResponse
    {
        $eventModel = $this->authorizedEvent($event);
        $mediaVariant = $this->asset($eventModel, $asset)->variants()->where('variant_key', $variant)->firstOrFail();

        return Storage::disk($mediaVariant->storage_disk)->response(
            $mediaVariant->storage_path,
            null,
            ['Content-Type' => $mediaVariant->mime_type, 'Cache-Control' => 'private, max-age=31536000, immutable'],
        );
    }

    private function authorizedEvent(string $event, string $ability = 'view'): Event
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize($ability, $model);

        return $model;
    }

    private function asset(Event $event, string $asset): MediaAsset
    {
        return $event->mediaAssets()->findOrFail($asset);
    }
}
