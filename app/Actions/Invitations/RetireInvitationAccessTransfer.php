<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessTransferStatus;
use App\Models\InvitationAccessTransferRequest;

final class RetireInvitationAccessTransfer
{
    public function handle(InvitationAccessTransferRequest $transfer, InvitationAccessTransferStatus $status): void
    {
        $now = now();
        $timestamp = match ($status) {
            InvitationAccessTransferStatus::Approved => ['approved_at' => $now],
            InvitationAccessTransferStatus::Rejected => ['rejected_at' => $now],
            InvitationAccessTransferStatus::Invalidated, InvitationAccessTransferStatus::Expired => ['invalidated_at' => $now],
            default => [],
        };
        $transfer->forceFill([
            'status' => $status,
            'current_slot' => null,
            ...$timestamp,
        ])->save();
    }

    public function revokePendingCredential(InvitationAccessTransferRequest $transfer): void
    {
        $credential = $transfer->requestedCredential()->lockForUpdate()->first();
        if ($credential !== null) {
            $credential->forceFill(['current_slot' => null, 'revoked_at' => now()])->save();
        }
    }
}
