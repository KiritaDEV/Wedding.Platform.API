<?php

namespace App\Invitations;

use App\Enums\InvitationTrustState;
use App\Models\InvitationBrowserCredential;

final readonly class OpenInvitationResult
{
    public function __construct(
        public InvitationTrustState $trustState,
        public bool $claimedNow,
        public bool $canOpen,
        public ?InvitationBrowserCredential $credential = null,
        public ?string $secret = null,
    ) {}

    public function toArray(): array
    {
        return [
            'trustState' => $this->trustState->value,
            'claimedNow' => $this->claimedNow,
            'canOpen' => $this->canOpen,
        ];
    }
}
