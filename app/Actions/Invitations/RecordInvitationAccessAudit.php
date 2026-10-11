<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Models\Invitation;
use App\Models\InvitationAccessAudit;
use App\Models\User;

class RecordInvitationAccessAudit
{
    /** @param array<string, string|null> $metadata */
    public function handle(
        Invitation $invitation,
        InvitationAccessAuditEvent $event,
        InvitationAccessAuditActor $actor,
        array $metadata = [],
        ?User $user = null,
    ): InvitationAccessAudit {
        return InvitationAccessAudit::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'event_type' => $event,
            'actor_type' => $actor,
            'actor_user_id' => $actor === InvitationAccessAuditActor::ManagementUser ? $user?->id : null,
            'actor_name_snapshot' => $actor === InvitationAccessAuditActor::ManagementUser ? $user?->name : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
