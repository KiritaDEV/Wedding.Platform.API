<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Invitations\InvitationTrustContext;
use App\Invitations\PrivateInvitationRsvpView;
use App\Website\RenderableWebsite;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Http\Request;

final class ReadPrivateInvitationSite
{
    /** @return array<string, mixed> */
    public function handle(string $token, Request $request): array
    {
        $context = app(ResolveInvitationTrustContext::class)->handle($token, $request);
        $invitation = $context->invitation;
        $event = $invitation->event;
        $event->load('publishedWebsite.event', 'publishedWebsite.sections.website');

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

        if ($event->publishedWebsite === null) {
            return [
                'status' => 'unpublished',
                'event' => ['name' => $event->name, 'slug' => $event->slug],
                'website' => null,
                'privateInvitation' => $privateInvitation,
            ];
        }

        $website = $event->publishedWebsite;
        $renderable = (new RenderableWebsite($website))->toArray($request);
        $renderable['sections'] = array_values(array_filter(
            $renderable['sections'],
            static fn (array $section): bool => $section['isEnabled'],
        ));
        $mediaIds = $website->sections->where('is_enabled', true)->flatMap(
            fn ($section) => app(WebsiteSectionMediaReferences::class)->extract($section->type, $section->content, $section->appearance),
        )->pluck('assetId')->unique();
        $renderable['media'] = array_intersect_key((array) $renderable['media'], array_fill_keys($mediaIds->all(), true));

        return [
            'status' => 'published',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'type' => $event->type->value,
                'eventDate' => $event->event_date?->toDateString(),
                'startTime' => $event->start_time === null ? null : substr($event->start_time, 0, 5),
                'timeZone' => $event->time_zone,
            ],
            'website' => $renderable,
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
