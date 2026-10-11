<?php

namespace App\Http\Resources;

use App\Enums\InvitationAccessAuditActor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationAccessAuditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        $actor = ['type' => $this->actor_type->value];
        if ($this->actor_type === InvitationAccessAuditActor::ManagementUser) {
            $actor['name'] = $this->actor_name_snapshot;
        } elseif ($this->actor_type === InvitationAccessAuditActor::PrivateBrowser) {
            $actor['browserFamily'] = $metadata['browserFamily'] ?? null;
            $actor['platform'] = $metadata['platform'] ?? null;
        }

        $details = [];
        foreach (['reason', 'requesterBrowserFamily', 'requesterPlatform'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $details[$key] = $metadata[$key];
            }
        }

        return [
            'id' => $this->id,
            'type' => $this->event_type->value,
            'occurredAt' => $this->occurred_at->toISOString(),
            'actor' => $actor,
            'details' => $details === [] ? null : $details,
        ];
    }
}
