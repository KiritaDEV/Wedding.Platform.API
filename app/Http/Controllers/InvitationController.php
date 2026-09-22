<?php

namespace App\Http\Controllers;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\DeleteInvitation;
use App\Actions\Invitations\ListInvitations;
use App\Actions\Invitations\MoveGuest;
use App\Actions\Invitations\SetInvitationStatus;
use App\Actions\Invitations\UpdateInvitation;
use App\Enums\InvitationStatus;
use App\Http\Requests\InvitationListRequest;
use App\Http\Requests\MoveGuestRequest;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Requests\UpdateInvitationRequest;
use App\Http\Resources\GuestResource;
use App\Http\Resources\InvitationListResource;
use App\Http\Resources\InvitationOptionResource;
use App\Http\Resources\InvitationResource;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    public function options(string $event): AnonymousResourceCollection
    {
        $invitations = $this->authorizedEvent($event)->invitations()
            ->with('guests')->withCount('guests')->get()
            ->sort(fn (Invitation $left, Invitation $right): int => strcasecmp($left->effectiveName(), $right->effectiveName()) ?: strcmp($left->id, $right->id))
            ->values();

        return InvitationOptionResource::collection($invitations);
    }

    public function index(InvitationListRequest $request, ListInvitations $list, string $event): JsonResponse
    {
        $result = $list->handle($this->authorizedEvent($event), $request->filters());
        $paginator = $result['invitations'];

        return response()->json([
            'data' => InvitationListResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'pagination' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'summary' => $result['summary'],
                'lifecycleCounts' => $result['lifecycleCounts'],
            ],
        ]);
    }

    public function show(Request $request, string $event, string $invitation): InvitationResource
    {
        return new InvitationResource($this->invitation($this->authorizedEvent($event), $invitation)->load('guests.weddingRoles'));
    }

    public function store(StoreInvitationRequest $request, CreateInvitation $create, string $event): JsonResponse
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $state = $request->invitationState();
        $invitation = $create->handle($eventModel, $state['guests'], $state['custom_name'], $state['custom_roles']);

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    public function update(UpdateInvitationRequest $request, UpdateInvitation $update, string $event, string $invitation): InvitationResource
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $state = $request->invitationState();

        return new InvitationResource($update->handle(
            $this->invitation($eventModel, $invitation), $state['guests'], $state['custom_name'], $state['custom_roles'],
        ));
    }

    public function activate(SetInvitationStatus $setStatus, string $event, string $invitation): InvitationResource
    {
        $eventModel = $this->authorizedEvent($event, 'update');

        return new InvitationResource($setStatus->handle($this->invitation($eventModel, $invitation), InvitationStatus::Active));
    }

    public function deactivate(SetInvitationStatus $setStatus, string $event, string $invitation): InvitationResource
    {
        $eventModel = $this->authorizedEvent($event, 'update');

        return new InvitationResource($setStatus->handle($this->invitation($eventModel, $invitation), InvitationStatus::Inactive));
    }

    public function move(MoveGuestRequest $request, MoveGuest $move, string $event, string $sourceInvitation, string $guest): GuestResource
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $source = $this->invitation($eventModel, $sourceInvitation);
        $guestModel = $source->guests()->findOrFail($guest);
        $destination = $this->invitation($eventModel, $request->validated('destinationInvitationId'));

        return new GuestResource($move->handle($source, $guestModel, $destination));
    }

    public function destroy(DeleteInvitation $delete, string $event, string $invitation): Response
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $delete->handle($this->invitation($eventModel, $invitation));

        return response()->noContent();
    }

    private function authorizedEvent(string $event, string $ability = 'view'): Event
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize($ability, $model);

        return $model;
    }

    private function invitation(Event $event, string $invitation): Invitation
    {
        return $event->invitations()->findOrFail($invitation);
    }
}
