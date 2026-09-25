<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Enums\InvitationTrustState;
use App\Enums\PrivateInvitationLinkStatus;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveInvitationAccessTransfer
{
    /** @return array{accessState: string} */
    public function handle(string $token, Request $request, bool $approve): array
    {
        $result = DB::transaction(function () use ($token, $request, $approve): array {
            $context = app(ResolveInvitationTrustContext::class)->handle($token, $request);
            if ($context->linkStatus !== PrivateInvitationLinkStatus::Current || $context->trustState !== InvitationTrustState::Trusted) {
                throw new NotFoundHttpException;
            }

            Event::query()->whereKey($context->invitation->event_id)->lockForUpdate()->firstOrFail();
            $invitation = Invitation::query()->whereKey($context->invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->status !== InvitationStatus::Active) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is inactive.']);
            }
            $current = $invitation->currentBrowserCredential()->lockForUpdate()->first();
            if ($current === null || ! app(ResolveInvitationTrustContext::class)->proves($current, $request)) {
                throw new NotFoundHttpException;
            }
            $transfer = $invitation->activeAccessTransferRequest()->lockForUpdate()->first();
            if ($transfer === null) {
                throw ValidationException::withMessages(['access' => 'There is no active access request.']);
            }
            if ($transfer->expires_at->isPast()) {
                app(RetireInvitationAccessTransfer::class)->revokePendingCredential($transfer);
                app(RetireInvitationAccessTransfer::class)->handle($transfer, InvitationAccessTransferStatus::Expired);
                app(RecordInvitationAccessAudit::class)->handle(
                    $invitation,
                    InvitationAccessAuditEvent::AccessTransferExpired,
                    InvitationAccessAuditActor::System,
                    ['requesterBrowserFamily' => $transfer->requested_browser_family, 'requesterPlatform' => $transfer->requested_platform],
                );

                return ['accessState' => 'expired'];
            }

            $pending = $transfer->requestedCredential()->lockForUpdate()->firstOrFail();
            if ($approve) {
                $current->forceFill(['current_slot' => null, 'revoked_at' => now()])->save();
                $pending->forceFill(['current_slot' => 'current', 'revoked_at' => null])->save();
                app(RetireInvitationAccessTransfer::class)->handle($transfer, InvitationAccessTransferStatus::Approved);
                app(RecordInvitationAccessAudit::class)->handle(
                    $invitation,
                    InvitationAccessAuditEvent::AccessTransferApproved,
                    InvitationAccessAuditActor::PrivateBrowser,
                    [
                        'browserFamily' => $current->browser_family,
                        'platform' => $current->platform,
                        'requesterBrowserFamily' => $transfer->requested_browser_family,
                        'requesterPlatform' => $transfer->requested_platform,
                    ],
                );

                return ['accessState' => 'transferred'];
            }

            $pending->forceFill(['current_slot' => null, 'revoked_at' => now()])->save();
            app(RetireInvitationAccessTransfer::class)->handle($transfer, InvitationAccessTransferStatus::Rejected);
            app(RecordInvitationAccessAudit::class)->handle(
                $invitation,
                InvitationAccessAuditEvent::AccessTransferRejected,
                InvitationAccessAuditActor::PrivateBrowser,
                [
                    'browserFamily' => $current->browser_family,
                    'platform' => $current->platform,
                    'requesterBrowserFamily' => $transfer->requested_browser_family,
                    'requesterPlatform' => $transfer->requested_platform,
                ],
            );

            return ['accessState' => 'rejected'];
        }, 3);

        if ($result['accessState'] === 'expired') {
            throw ValidationException::withMessages(['access' => 'The access request has expired.']);
        }

        return $result;
    }
}
