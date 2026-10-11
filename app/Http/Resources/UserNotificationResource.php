<?php

namespace App\Http\Resources;

use App\Enums\UserNotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $details = (object) [];
        if ($this->type === UserNotificationType::AccessTransferRequested) {
            $details = [
                'browserFamily' => $metadata['browserFamily'] ?? null,
                'platform' => $metadata['platform'] ?? null,
            ];
        }

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'event' => ['id' => $this->event_id, 'name' => $this->event_name_snapshot],
            'invitation' => ['id' => $this->invitation_id, 'name' => $this->invitation_name_snapshot],
            'details' => $details,
            'occurredAt' => $this->occurred_at->toISOString(),
            'readAt' => $this->read_at?->toISOString(),
        ];
    }
}
