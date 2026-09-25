<?php

namespace App\Actions\Invitations;

use App\Actions\Notifications\RecordManagementNotifications;
use App\Enums\GuestStatus;
use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Enums\RsvpActorType;
use App\Enums\UserNotificationSource;
use App\Enums\UserNotificationType;
use App\Invitations\PrivateInvitationRsvpView;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Invitation;
use App\Models\RsvpSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SubmitPrivateInvitationRsvp
{
    /** @param array<int, array{guestId: string, response: string}> $responses */
    public function handle(string $token, array $responses, Request $request): array
    {
        return DB::transaction(function () use ($token, $responses, $request): array {
            $context = app(ResolveInvitationTrustContext::class)->handle($token, $request);
            if ($context->linkStatus !== PrivateInvitationLinkStatus::Current || $context->trustState !== InvitationTrustState::Trusted) {
                throw new NotFoundHttpException;
            }

            $event = Event::query()->whereKey($context->invitation->event_id)->lockForUpdate()->firstOrFail();
            $invitation = Invitation::query()->whereKey($context->invitation->id)->lockForUpdate()->firstOrFail();
            $invitation->setRelation('event', $event);

            $credential = $invitation->currentBrowserCredential()->lockForUpdate()->first();
            if ($credential === null || ! app(ResolveInvitationTrustContext::class)->proves($credential, $request)) {
                throw new NotFoundHttpException;
            }
            if ($invitation->status !== InvitationStatus::Active) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is inactive.']);
            }
            if (! $event->rsvp_is_open) {
                throw ValidationException::withMessages(['rsvp' => 'RSVP responses are closed.']);
            }
            if ($event->hasRsvpDeadlineExpired()) {
                throw ValidationException::withMessages(['rsvp' => 'The RSVP deadline has passed.']);
            }

            $active = $invitation->guests()->where('status', GuestStatus::Active->value)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $desired = collect($responses)->keyBy('guestId');
            if ($desired->count() !== count($responses) || $desired->keys()->sort()->values()->all() !== $active->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['responses' => 'Responses must contain every current active Guest exactly once.']);
            }

            $changed = $active->contains(fn (Guest $guest): bool => $guest->rsvp_response?->value !== $desired[$guest->id]['response']);
            if (! $changed) {
                return ['changed' => false, 'confirmation' => null, 'rsvp' => app(PrivateInvitationRsvpView::class)->make($invitation)];
            }

            $hasPriorPrivateSubmission = $invitation->rsvpSubmissions()
                ->where('actor_type', RsvpActorType::PrivateInvitation->value)->exists();
            foreach ($active as $guest) {
                $guest->update(['rsvp_response' => $desired[$guest->id]['response']]);
            }

            $submission = RsvpSubmission::query()->create([
                'event_id' => $event->id,
                'invitation_id' => $invitation->id,
                'actor_type' => RsvpActorType::PrivateInvitation,
                'actor_user_id' => null,
                'actor_name_snapshot' => null,
                'note' => null,
            ]);
            foreach ($active->values() as $order => $guest) {
                $submission->items()->create([
                    'guest_id' => $guest->id,
                    'guest_name_snapshot' => $guest->fullName(),
                    'rsvp_response' => $desired[$guest->id]['response'],
                    'snapshot_order' => $order,
                ]);
            }

            app(RecordManagementNotifications::class)->handle(
                $event,
                $invitation,
                $hasPriorPrivateSubmission ? UserNotificationType::GuestRsvpUpdated : UserNotificationType::GuestRsvpReceived,
                UserNotificationSource::RsvpSubmission,
                $submission->id,
            );

            return [
                'changed' => true,
                'confirmation' => $hasPriorPrivateSubmission ? 'updated' : 'received',
                'rsvp' => app(PrivateInvitationRsvpView::class)->make($invitation->fresh()->setRelation('event', $event)),
            ];
        }, 3);
    }
}
