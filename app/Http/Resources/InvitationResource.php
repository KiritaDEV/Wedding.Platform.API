<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customName' => $this->custom_name,
            'displayName' => $this->effectiveName(),
            'status' => $this->status->value,
            'rsvp' => $this->rsvpSummary(),
            'lastResponse' => $this->rsvp_submissions_max_created_at === null ? null : CarbonImmutable::parse($this->rsvp_submissions_max_created_at)->toISOString(),
            'canPermanentlyDelete' => $this->canPermanentlyDelete(),
            'guests' => GuestResource::collection($this->whenLoaded('guests')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
