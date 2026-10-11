<?php

namespace App\Actions\Invitations;

use App\Actions\Notifications\RecordManagementNotifications;
use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Enums\PrivateInvitationLinkStatus;
use App\Enums\UserNotificationSource;
use App\Enums\UserNotificationType;
use App\Invitations\AccessTransferRequestResult;
use App\Invitations\BrowserCredentialSecret;
use App\Invitations\BrowserDisplayMetadata;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationAccessTransferRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RequestInvitationAccessTransfer
{
    public function handle(string $token, Request $request): AccessTransferRequestResult
    {
        return DB::transaction(function () use ($token, $request): AccessTransferRequestResult {
            $context = app(ResolveInvitationTrustContext::class)->handle($token, $request);
            if ($context->linkStatus !== PrivateInvitationLinkStatus::Current) {
                throw new NotFoundHttpException;
            }

            $invitation = Invitation::query()->whereKey($context->invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->status !== InvitationStatus::Active) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is inactive.']);
            }
            $currentCredential = $invitation->currentBrowserCredential()->lockForUpdate()->first();
            if ($currentCredential === null || app(ResolveInvitationTrustContext::class)->proves($currentCredential, $request)) {
                throw ValidationException::withMessages(['access' => 'Access transfer is not available.']);
            }

            $active = $invitation->activeAccessTransferRequest()->with('requestedCredential')->lockForUpdate()->first();
            if ($active !== null && $active->expires_at->isPast()) {
                app(RetireInvitationAccessTransfer::class)->revokePendingCredential($active);
                app(RetireInvitationAccessTransfer::class)->handle($active, InvitationAccessTransferStatus::Expired);
                app(RecordInvitationAccessAudit::class)->handle(
                    $invitation,
                    InvitationAccessAuditEvent::AccessTransferExpired,
                    InvitationAccessAuditActor::System,
                    ['requesterBrowserFamily' => $active->requested_browser_family, 'requesterPlatform' => $active->requested_platform],
                );
                $active = null;
            }
            if ($active !== null) {
                $sameRequester = app(ResolveInvitationTrustContext::class)->proves($active->requestedCredential, $request);

                return new AccessTransferRequestResult($sameRequester ? 'pending' : 'request_pending', $active);
            }

            $metadata = app(BrowserDisplayMetadata::class)->fromUserAgent($request->userAgent());
            $secret = BrowserCredentialSecret::generate();
            $credential = $invitation->browserCredentials()->forceCreate([
                'secret_hash' => BrowserCredentialSecret::hash($secret),
                'current_slot' => null,
                'browser_family' => $metadata['browser'],
                'platform' => $metadata['platform'],
            ]);
            $now = now();
            $transfer = InvitationAccessTransferRequest::query()->forceCreate([
                'invitation_id' => $invitation->id,
                'requested_credential_id' => $credential->id,
                'status' => InvitationAccessTransferStatus::Pending,
                'current_slot' => 'active',
                'requested_browser_family' => $metadata['browser'],
                'requested_platform' => $metadata['platform'],
                'requested_at' => $now,
                'expires_at' => $now->copy()->addHours(24),
            ]);
            app(RecordInvitationAccessAudit::class)->handle(
                $invitation,
                InvitationAccessAuditEvent::AccessTransferRequested,
                InvitationAccessAuditActor::PrivateBrowser,
                ['browserFamily' => $metadata['browser'], 'platform' => $metadata['platform']],
            );
            $event = Event::query()->findOrFail($invitation->event_id);
            app(RecordManagementNotifications::class)->handle(
                $event,
                $invitation,
                UserNotificationType::AccessTransferRequested,
                UserNotificationSource::AccessTransferRequest,
                $transfer->id,
                ['browserFamily' => $metadata['browser'], 'platform' => $metadata['platform']],
            );

            return new AccessTransferRequestResult('pending', $transfer, $credential, $secret);
        }, 3);
    }
}
