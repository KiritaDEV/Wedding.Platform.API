<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effectiveName' => $this->effectiveName(),
            'status' => $this->status->value,
            'guestCount' => $this->guests_count,
        ];
    }
}
