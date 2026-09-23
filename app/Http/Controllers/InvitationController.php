<?php

namespace App\Http\Controllers;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\DeleteInvitation;
use App\Actions\Invitations\ListInvitations;
use App\Actions\Invitations\MoveGuest;
use App\Actions\Invitations\SetInvitationStatus;
use App\Actions\Invitations\UpdateInvitation;
use App\Actions\Invitations\UpdateInvitationRsvp;
use App\Enums\InvitationStatus;
use App\Http\Requests\InvitationListRequest;
use App\Http\Requests\MoveGuestRequest;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Requests\UpdateInvitationRequest;
use App\Http\Requests\UpdateInvitationRsvpRequest;
use App\Http\Resources\GuestResource;
use App\Http\Resources\InvitationListResource;
use App\Http\Resources\InvitationOptionResource;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\RsvpSubmissionResource;
use App\Models\Event;
use App\Models\Invitation;
use Carbon\CarbonImmutable;
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
            ->with('guests')->withCount(['guests as guests_count' => fn ($query) => $query->where('status', 'active')])->get()
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
        $model = $this->invitation($this->authorizedEvent($event), $invitation)
            ->load(['guests' => fn ($query) => $query->with('weddingRoles')->withExists('rsvpSubmissionItems')])
            ->loadExists('rsvpSubmissions')->loadMax('rsvpSubmissions', 'created_at');

        return new InvitationResource($model);
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
            $this->invitation($eventModel, $invitation), $state['guests'], $state['custom_name'], $state['custom_roles'], $state['deleted_guest_ids'],
        ));
    }

    public function updateRsvp(UpdateInvitationRsvpRequest $request, UpdateInvitationRsvp $update, string $event, string $invitation): JsonResponse
    {
        $eventModel = $this->authorizedEvent($event, 'update');
        $state = $request->desiredState();
        $result = $update->handle($this->invitation($eventModel, $invitation), $request->user(), $state['responses'], $state['note']);
        $invitationModel = $result['invitation'];

        return response()->json(['data' => [
            'changed' => $result['changed'],
            'rsvp' => $invitationModel->rsvpSummary(),
            'guests' => GuestResource::collection($invitationModel->guests)->resolve($request),
            'submission' => $result['submission'] ? (new RsvpSubmissionResource($result['submission']))->resolve($request) : null,
            'lastResponse' => $invitationModel->rsvp_submissions_max_created_at === null ? null : CarbonImmutable::parse($invitationModel->rsvp_submissions_max_created_at)->toISOString(),
        ]]);
    }

    public function rsvpHistory(Request $request, string $event, string $invitation): AnonymousResourceCollection
    {
        $eventModel = $this->authorizedEvent($event);
        $submissions = $this->invitation($eventModel, $invitation)->rsvpSubmissions()
            ->with('items')->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return RsvpSubmissionResource::collection($submissions);
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
