<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Invitations\BrowserCredentialSecret;
use App\Invitations\BrowserDisplayMetadata;
use App\Invitations\OpenInvitationResult;
use App\Invitations\PrivateInvitationToken;
use App\Models\Invitation;
use App\Models\InvitationPrivateLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OpenPrivateInvitation
{
    public function handle(string $token, Request $request): OpenInvitationResult
    {
        return DB::transaction(function () use ($token, $request): OpenInvitationResult {
            $link = InvitationPrivateLink::query()
                ->where('token_hash', PrivateInvitationToken::hash($token))
                ->where('current_slot', 'current')
                ->first();
            if ($link === null) {
                throw new NotFoundHttpException;
            }

            $invitation = Invitation::query()->whereKey($link->invitation_id)->lockForUpdate()->firstOrFail();
            $credential = $invitation->currentBrowserCredential()->lockForUpdate()->first();
            if ($credential !== null) {
                if (app(ResolveInvitationTrustContext::class)->proves($credential, $request)) {
                    return new OpenInvitationResult(InvitationTrustState::Trusted, false, true);
                }

                return new OpenInvitationResult(InvitationTrustState::ClaimedElsewhere, false, false);
            }

            if ($invitation->status !== InvitationStatus::Active) {
                return new OpenInvitationResult(InvitationTrustState::Unclaimed, false, false);
            }

            $secret = BrowserCredentialSecret::generate();
            $metadata = app(BrowserDisplayMetadata::class)->fromUserAgent($request->userAgent());
            $credential = $invitation->browserCredentials()->forceCreate([
                'secret_hash' => BrowserCredentialSecret::hash($secret),
                'current_slot' => 'current',
                'browser_family' => $metadata['browser'],
                'platform' => $metadata['platform'],
            ]);
            app(RecordInvitationAccessAudit::class)->handle(
                $invitation,
                InvitationAccessAuditEvent::TrustedAccessClaimed,
                InvitationAccessAuditActor::PrivateBrowser,
                ['browserFamily' => $metadata['browser'], 'platform' => $metadata['platform']],
            );

            return new OpenInvitationResult(InvitationTrustState::Trusted, true, true, $credential, $secret);
        }, 3);
    }
}
