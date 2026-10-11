<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationListGuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'relationship' => $this->relationship->value,
            'side' => $this->side->value,
            'status' => $this->status->value,
            'weddingRoles' => WeddingRoleResource::collection($this->whenLoaded('weddingRoles')),
            'rsvpResponse' => $this->rsvp_response?->value,
            'rsvpStatus' => $this->rsvp_response?->value ?? 'pending',
            'canPermanentlyDelete' => $this->canPermanentlyDelete(),
        ];
    }
}
