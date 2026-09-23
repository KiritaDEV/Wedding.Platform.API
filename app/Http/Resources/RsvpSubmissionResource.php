<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RsvpSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->created_at?->toISOString(),
            'actorType' => $this->actor_type->value,
            'actorUserId' => $this->actor_user_id,
            'actorName' => $this->actor_name_snapshot,
            'note' => $this->note,
            'items' => $this->items->map(fn ($item): array => [
                'guestId' => $item->guest_id,
                'guestName' => $item->guest_name_snapshot,
                'response' => $item->rsvp_response?->value,
            ]),
        ];
    }
}
