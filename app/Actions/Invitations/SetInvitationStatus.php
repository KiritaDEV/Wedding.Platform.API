<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Enums\InvitationAccessInvalidationReason;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SetInvitationStatus
{
    public function handle(Invitation $invitation, InvitationStatus $status, ?User $actor = null): Invitation
    {
        DB::transaction(function () use ($invitation, $status, $actor): void {
            $locked = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $status) {
                $locked->update(['status' => $status]);
            }
            if ($status === InvitationStatus::Inactive) {
                $transfer = $locked->activeAccessTransferRequest()->lockForUpdate()->first();
                if ($transfer !== null) {
                    app(RetireInvitationAccessTransfer::class)->revokePendingCredential($transfer);
                    app(RetireInvitationAccessTransfer::class)->handle($transfer, InvitationAccessTransferStatus::Invalidated);
                    app(RecordInvitationAccessAudit::class)->handle(
                        $locked,
                        InvitationAccessAuditEvent::AccessTransferInvalidated,
                        $actor === null ? InvitationAccessAuditActor::System : InvitationAccessAuditActor::ManagementUser,
                        [
                            'reason' => InvitationAccessInvalidationReason::InvitationInactive->value,
                            'requesterBrowserFamily' => $transfer->requested_browser_family,
                            'requesterPlatform' => $transfer->requested_platform,
                        ],
                        $actor,
                    );
                }
            }
        }, 3);

        return $invitation->fresh(['guests.weddingRoles']);
    }
}
