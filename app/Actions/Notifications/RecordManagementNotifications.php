<?php

namespace App\Actions\Notifications;

use App\Enums\EventMembershipRole;
use App\Enums\UserNotificationSource;
use App\Enums\UserNotificationType;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\UserNotification;
use Illuminate\Support\Str;

class RecordManagementNotifications
{
    /** @param array<string, string|null> $metadata */
    public function handle(
        Event $event,
        Invitation $invitation,
        UserNotificationType $type,
        UserNotificationSource $sourceType,
        string $sourceId,
        array $metadata = [],
    ): void {
        $recipientIds = $event->memberships()
            ->whereIn('role', [EventMembershipRole::Owner->value, EventMembershipRole::Admin->value])
            ->pluck('user_id')->unique()->values();
        if ($recipientIds->isEmpty()) {
            return;
        }

        $now = now();
        $invitation->loadMissing('guests');
        $rows = $recipientIds->map(fn (string $recipientId): array => [
            'id' => (string) Str::ulid(),
            'recipient_user_id' => $recipientId,
            'event_id' => $event->id,
            'invitation_id' => $invitation->id,
            'type' => $type->value,
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'event_name_snapshot' => $event->name,
            'invitation_name_snapshot' => $invitation->effectiveName(),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'read_at' => null,
            'created_at' => $now,
        ])->all();

        UserNotification::query()->insert($rows);
    }
}
