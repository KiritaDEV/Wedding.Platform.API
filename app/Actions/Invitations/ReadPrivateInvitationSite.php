<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Invitations\InvitationTrustContext;
use App\Invitations\PrivateInvitationRsvpView;
use App\Website\Publishing\PublishedWebsitePresentation;
use Illuminate\Http\Request;

final class ReadPrivateInvitationSite
{
    /** @return array<string, mixed> */
    public function handle(string $token, Request $request): array
    {
        $context = app(ResolveInvitationTrustContext::class)->handle($token, $request);
        $invitation = $context->invitation;
        $event = $invitation->event;
        $projection = app(PublishedWebsitePresentation::class);
        $snapshot = $projection->resolve($event, private: true);

        $privateInvitation = $context->toArray();
        $privateInvitation['rsvp'] = null;
        $privateInvitation['handoffOnly'] = $request->boolean('handoffOnly');
        $privateInvitation['currentPath'] = $invitation->currentPrivateLink()->firstOrFail()->path();
        $privateInvitation['accessState'] = null;
        $privateInvitation['accessRequest'] = null;
        if (! $privateInvitation['handoffOnly'] && $context->trustState === InvitationTrustState::Trusted) {
            $privateInvitation['rsvp'] = app(PrivateInvitationRsvpView::class)->make($context->invitation);
        }
        if (! $privateInvitation['handoffOnly']) {
            $this->addAccessTransferRuntime($privateInvitation, $context, $request);
        }

        if ($snapshot === null) {
            return [
                'status' => 'unpublished',
                'event' => ['name' => $event->name, 'slug' => $event->slug],
                'website' => null,
                'privateInvitation' => $privateInvitation,
            ];
        }

        return [
            'status' => 'published',
            'event' => $snapshot->event,
            'website' => $projection->website($snapshot, $event, $token),
            'privateInvitation' => $privateInvitation,
        ];
    }

    /** @param array<string, mixed> $runtime */
    private function addAccessTransferRuntime(array &$runtime, InvitationTrustContext $context, Request $request): void
    {
        $invitation = $context->invitation;
        $transfer = $invitation->activeAccessTransferRequest()
            ->with('requestedCredential')
            ->where('expires_at', '>', now())
            ->first();
        if ($transfer === null) {
            $runtime['accessState'] = $context->trustState === InvitationTrustState::ClaimedElsewhere
                && $invitation->status === InvitationStatus::Active
                && $context->linkStatus === PrivateInvitationLinkStatus::Current
                    ? 'can_request'
                    : null;

            return;
        }

        if ($context->trustState === InvitationTrustState::Trusted) {
            $runtime['accessRequest'] = [
                'browserFamily' => $transfer->requested_browser_family,
                'platform' => $transfer->requested_platform,
                'requestedAt' => $transfer->requested_at->toISOString(),
                'expiresAt' => $transfer->expires_at->toISOString(),
            ];

            return;
        }

        $isRequester = app(ResolveInvitationTrustContext::class)->proves($transfer->requestedCredential, $request);
        $runtime['accessState'] = $isRequester ? 'transfer_pending' : 'request_pending';
        if ($isRequester) {
            $runtime['accessRequest'] = [
                'requestedAt' => $transfer->requested_at->toISOString(),
                'expiresAt' => $transfer->expires_at->toISOString(),
            ];
        }
    }
}
