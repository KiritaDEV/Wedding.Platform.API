<?php

namespace App\Http\Controllers;

use App\Actions\Events\AddEventCollaborator;
use App\Actions\Events\RemoveEventCollaborator;
use App\Http\Requests\StoreEventCollaboratorRequest;
use App\Http\Resources\EventCollaboratorResource;
use App\Models\Event;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EventCollaboratorController extends Controller
{
    public function index(string $event): AnonymousResourceCollection
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize('manageCollaborators', $model);

        return EventCollaboratorResource::collection($model->memberships()->with('user')->orderBy('created_at')->orderBy('id')->get());
    }

    public function store(StoreEventCollaboratorRequest $request, string $event, AddEventCollaborator $action): EventCollaboratorResource
    {
        return new EventCollaboratorResource($action->handle(Event::query()->findOrFail($event), $request->validated('email')));
    }

    public function destroy(string $event, string $membership, RemoveEventCollaborator $action): Response
    {
        $action->handle(Event::query()->findOrFail($event), $membership);

        return response()->noContent();
    }
}
