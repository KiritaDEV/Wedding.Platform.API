<?php

namespace App\Http\Resources;

use App\Enums\GuestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $guestCount = $this->guests->where('status', GuestStatus::Active)->count();

        return [
            'id' => $this->id,
            'customName' => $this->custom_name,
            'effectiveName' => $this->effectiveName(),
            'status' => $this->status->value,
            'guestCount' => $guestCount,
            'totalGuestCount' => $this->guests->count(),
            'rsvp' => $this->rsvpSummary(),
            'lastResponse' => $this->rsvp_submissions_max_created_at === null ? null : CarbonImmutable::parse($this->rsvp_submissions_max_created_at)->toISOString(),
            'canPermanentlyDelete' => $this->canPermanentlyDelete(),
            'trustedAccess' => [
                'hasTrustedBrowser' => (bool) $this->current_browser_credential_exists,
                'hasPendingAccessRequest' => (bool) $this->active_access_transfer_request_exists,
            ],
            'guests' => InvitationListGuestResource::collection($this->guests),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
