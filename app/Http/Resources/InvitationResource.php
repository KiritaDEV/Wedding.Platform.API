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
            'trustedAccess' => [
                'hasTrustedBrowser' => (bool) ($this->current_browser_credential_exists ?? $this->currentBrowserCredential()->exists()),
                'hasPendingAccessRequest' => (bool) ($this->active_access_transfer_request_exists ?? $this->activeAccessTransferRequest()->exists()),
            ],
            'privateInvitation' => $this->whenLoaded('currentPrivateLink', fn (): array => [
                'path' => $this->currentPrivateLink->path(),
            ]),
            'guests' => GuestResource::collection($this->whenLoaded('guests')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
