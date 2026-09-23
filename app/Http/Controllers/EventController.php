<?php

namespace App\Http\Controllers;

use App\Actions\Events\CreateEvent;
use App\Actions\Events\UpdateEventRsvpSettings;
use App\Actions\Events\UpdateEventTiming;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRsvpSettingsRequest;
use App\Http\Requests\UpdateEventTimingRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = $request->user()->events()
            ->orderByDesc('events.updated_at')
            ->orderByDesc('events.id')
            ->get();

        return EventResource::collection($events);
    }

    public function store(StoreEventRequest $request, CreateEvent $createEvent): EventResource
    {
        $event = $createEvent->handle($request->user(), $request->eventAttributes());
        $event->load(['memberships' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new EventResource($event);
    }

    public function show(Request $request, string $event): EventResource
    {
        $model = Event::query()->findOrFail($event);

        Gate::authorize('view', $model);

        $model->load(['memberships' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new EventResource($model);
    }

    public function updateTiming(
        UpdateEventTimingRequest $request,
        UpdateEventTiming $updateTiming,
        string $event,
    ): EventResource {
        $model = Event::query()->findOrFail($event);
        Gate::authorize('update', $model);
        $updateTiming->handle($model, $request->timingAttributes());
        $model->load(['memberships' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new EventResource($model);
    }

    public function updateRsvpSettings(UpdateEventRsvpSettingsRequest $request, UpdateEventRsvpSettings $update, string $event): EventResource
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize('update', $model);
        $model = $update->handle($model, $request->settings());
        $model->load(['memberships' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new EventResource($model);
    }
}
