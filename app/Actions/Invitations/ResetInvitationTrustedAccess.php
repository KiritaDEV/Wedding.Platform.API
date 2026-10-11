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

final class ResetInvitationTrustedAccess
{
    /** @return array{changed: bool, trustState: string, hasPendingAccessRequest: bool} */
    public function handle(Event $event, Invitation $invitation, ?User $actor = null): array
    {
        $changed = DB::transaction(function () use ($event, $invitation, $actor): bool {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $locked = Invitation::query()
                ->where('event_id', $event->id)
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $current = $locked->currentBrowserCredential()->lockForUpdate()->first();
            $transfer = $locked->activeAccessTransferRequest()->lockForUpdate()->first();
            $pending = $transfer?->requestedCredential()->lockForUpdate()->first();

            if ($current !== null) {
                $current->forceFill(['current_slot' => null, 'revoked_at' => now()])->save();
            }
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
                        'reason' => InvitationAccessInvalidationReason::TrustedAccessReset->value,
                        'requesterBrowserFamily' => $transfer->requested_browser_family,
                        'requesterPlatform' => $transfer->requested_platform,
                    ],
                    $actor,
                );
            }

            $changed = $current !== null || $transfer !== null || $pending !== null;
            if ($changed) {
                app(RecordInvitationAccessAudit::class)->handle(
                    $locked,
                    InvitationAccessAuditEvent::TrustedAccessReset,
                    $actor === null ? InvitationAccessAuditActor::System : InvitationAccessAuditActor::ManagementUser,
                    user: $actor,
                );
            }

            return $changed;
        }, 3);

        return [
            'changed' => $changed,
            'trustState' => 'unclaimed',
            'hasPendingAccessRequest' => false,
        ];
    }
}
