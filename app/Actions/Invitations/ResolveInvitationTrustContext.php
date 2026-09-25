<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Invitations\BrowserCredentialSecret;
use App\Invitations\InvitationTrustContext;
use App\Invitations\InvitationTrustCookie;
use App\Models\InvitationBrowserCredential;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveInvitationTrustContext
{
    public function handle(string $token, Request $request): InvitationTrustContext
    {
        $resolution = app(ResolvePrivateInvitationLink::class)->handle($token);
        if ($resolution->status === PrivateInvitationLinkStatus::Unknown) {
            throw new NotFoundHttpException;
        }

        $invitation = $resolution->link->invitation;
        $credential = $invitation->currentBrowserCredential()->first();
        $trusted = $credential !== null && $this->proves($credential, $request);

        if ($resolution->status === PrivateInvitationLinkStatus::Historical && ! $trusted) {
            throw new NotFoundHttpException;
        }

        $trustState = match (true) {
            $trusted => InvitationTrustState::Trusted,
            $credential !== null => InvitationTrustState::ClaimedElsewhere,
            default => InvitationTrustState::Unclaimed,
        };

        return new InvitationTrustContext(
            $resolution->status,
            $invitation->status->value,
            $trustState,
            $trusted || ($credential === null && $invitation->status === InvitationStatus::Active),
            $resolution->status === PrivateInvitationLinkStatus::Historical
                ? $invitation->currentPrivateLink()->firstOrFail()->path()
                : null,
            $invitation,
        );
    }

    public function proves(InvitationBrowserCredential $credential, Request $request): bool
    {
        $secret = $request->cookie(app(InvitationTrustCookie::class)->name($credential));

        return is_string($secret) && BrowserCredentialSecret::verifies($secret, $credential->secret_hash);
    }
}
