<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestResource extends JsonResource
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
            'rsvpResponse' => $this->rsvp_response?->value,
            'canPermanentlyDelete' => $this->canPermanentlyDelete(),
            'weddingRoles' => WeddingRoleResource::collection($this->whenLoaded('weddingRoles')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
