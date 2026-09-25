<?php

namespace App\Invitations;

use App\Models\InvitationAccessTransferRequest;
use App\Models\InvitationBrowserCredential;

final readonly class AccessTransferRequestResult
{
    public function __construct(
        public string $accessState,
        public InvitationAccessTransferRequest $transferRequest,
        public ?InvitationBrowserCredential $credential = null,
        public ?string $secret = null,
    ) {}

    public function toArray(): array
    {
        return [
            'accessState' => $this->accessState,
            'requestedAt' => $this->transferRequest->requested_at->toISOString(),
            'expiresAt' => $this->transferRequest->expires_at->toISOString(),
        ];
    }
}
