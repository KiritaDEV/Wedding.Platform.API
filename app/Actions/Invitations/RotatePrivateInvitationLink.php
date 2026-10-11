<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Enums\InvitationAccessInvalidationReason;
use App\Enums\InvitationAccessTransferStatus;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RotatePrivateInvitationLink
{
    /** @return array{privateInvitation: array{path: string}, trustedAccess: array{hasTrustedBrowser: bool, hasPendingAccessRequest: bool}} */
    public function handle(Event $event, Invitation $invitation, ?User $actor = null): array
    {
        return DB::transaction(function () use ($event, $invitation, $actor): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $locked = Invitation::query()
                ->where('event_id', $event->id)
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentLink = $locked->currentPrivateLink()->lockForUpdate()->firstOrFail();
            $currentCredential = $locked->currentBrowserCredential()->lockForUpdate()->first();
            $transfer = $locked->activeAccessTransferRequest()->lockForUpdate()->first();
            $pending = $transfer?->requestedCredential()->lockForUpdate()->first();

            $currentLink->forceFill(['current_slot' => null, 'retired_at' => now()])->save();
            $newLink = app(ProvisionPrivateInvitationLink::class)->handle($locked);

            if ($pending !== null) {
                $pending->forceFill(['current_slot' => null, 'revoked_at' => now()])->save();
            }
            if ($transfer !== null) {
                app(RetireInvitationAccessTransfer::class)->handle($transfer, InvitationAccessTransferStatus::Invalidated);
                app(RecordInvitationAccessAudit::class)->handle(
                    $locked,
                    InvitationAccessAuditEvent::AccessTransferInvalidated,
                    $actor === null ? InvitationAccessAuditActor::System : InvitationAccessAuditActor::ManagementUser,
                    [
                        'reason' => InvitationAccessInvalidationReason::PrivateLinkRotated->value,
                        'requesterBrowserFamily' => $transfer->requested_browser_family,
                        'requesterPlatform' => $transfer->requested_platform,
                    ],
                    $actor,
                );
            }
            app(RecordInvitationAccessAudit::class)->handle(
                $locked,
                InvitationAccessAuditEvent::PrivateLinkRotated,
                $actor === null ? InvitationAccessAuditActor::System : InvitationAccessAuditActor::ManagementUser,
                user: $actor,
            );

            return [
                'privateInvitation' => ['path' => $newLink->path()],
                'trustedAccess' => [
                    'hasTrustedBrowser' => $currentCredential !== null,
                    'hasPendingAccessRequest' => false,
                ],
            ];
        }, 3);
    }
}
