<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $guestCount = $this->guests->count();

        return [
            'id' => $this->id,
            'customName' => $this->custom_name,
            'effectiveName' => $this->effectiveName(),
            'status' => $this->status->value,
            'guestCount' => $guestCount,
            'rsvp' => [
                'status' => 'pending',
                'attending' => 0,
                'declined' => 0,
                'pending' => $guestCount,
            ],
            'lastResponse' => null,
            'guests' => InvitationListGuestResource::collection($this->guests),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
