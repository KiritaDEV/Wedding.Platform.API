<?php

namespace App\Invitations;

use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Models\Invitation;

final readonly class InvitationTrustContext
{
    public function __construct(
        public PrivateInvitationLinkStatus $linkStatus,
        public string $invitationStatus,
        public InvitationTrustState $trustState,
        public bool $canOpen,
        public ?string $currentPath = null,
        public ?Invitation $invitation = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'linkStatus' => $this->linkStatus->value,
            'invitationStatus' => $this->invitationStatus,
            'trustState' => $this->trustState->value,
            'canOpen' => $this->canOpen,
            'currentPath' => $this->currentPath,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
