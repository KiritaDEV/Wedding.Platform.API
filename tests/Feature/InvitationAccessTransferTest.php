<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\DeleteInvitation;
use App\Actions\Invitations\SetInvitationStatus;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Invitations\BrowserCredentialSecret;
use App\Invitations\InvitationTrustCookie;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationAccessTransferRequest;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationAccessTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_request_creates_one_hash_only_pending_credential_and_is_idempotent_for_same_browser(): void
    {
        [$invitation, $trustedCookie, $trustedSecret] = $this->claimedInvitation();
        $token = $invitation->currentPrivateLink->encrypted_token;
        $response = $this->withHeader('User-Agent', $this->chromeWindows())
            ->postJson('/api/private-invitations/access-requests', ['token' => $token])
            ->assertOk()->assertJsonPath('data.accessState', 'pending');
        $transfer = InvitationAccessTransferRequest::query()->firstOrFail();
        $pending = $transfer->requestedCredential;
        $pendingCookie = app(InvitationTrustCookie::class)->name($pending);
        $pendingSecret = $response->getCookie($pendingCookie, false)->getValue();

        $this->assertSame(43, strlen($pendingSecret));
        $this->assertTrue(BrowserCredentialSecret::verifies($pendingSecret, $pending->secret_hash));
        $this->assertNotSame($pendingSecret, $pending->getRawOriginal('secret_hash'));
        $this->assertNull($pending->current_slot);
        $this->assertNull($pending->revoked_at);
        $this->assertSame('Chrome', $transfer->requested_browser_family);
        $this->assertSame('Windows', $transfer->requested_platform);
        $this->assertEquals(24, $transfer->requested_at->diffInHours($transfer->expires_at));
        $this->assertSame($invitation->fresh()->currentBrowserCredential->id, $invitation->currentBrowserCredential->id);

        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->postJson('/api/private-invitations/access-requests', ['token' => $token])
            ->assertOk()->assertJsonPath('data.accessState', 'pending')->assertCookieMissing($pendingCookie);
        $this->withUnencryptedCookie($pendingCookie, 'different-browser-secret')
            ->postJson('/api/private-invitations/access-requests', ['token' => $token])
            ->assertOk()->assertJsonPath('data.accessState', 'request_pending');
        $this->assertDatabaseCount('invitation_access_transfer_requests', 1);
        $this->assertDatabaseCount('invitation_browser_credentials', 2);

        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->withUnencryptedCookie($pendingCookie, 'invalid-pending-secret')
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.accessRequest.browserFamily', 'Chrome')
            ->assertJsonPath('data.privateInvitation.accessRequest.platform', 'Windows')
            ->assertJsonMissingPath('data.privateInvitation.accessRequest.credentialId');
        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->withUnencryptedCookie($trustedCookie, 'invalid-trusted-secret')
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.accessState', 'transfer_pending')
            ->assertJsonPath('data.privateInvitation.rsvp', null)
            ->assertDontSee('First Guest');
    }

    public function test_approval_atomically_promotes_requester_and_demotes_old_browser_without_rsvp_effects(): void
    {
        [$invitation, $trustedCookie, $trustedSecret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->postJson('/api/private-invitations/access-requests/approve', ['token' => $token])->assertNotFound();
        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->postJson('/api/private-invitations/access-requests/approve', ['token' => $token])
            ->assertOk()->assertJsonPath('data.accessState', 'transferred');

        $transfer = InvitationAccessTransferRequest::query()->firstOrFail();
        $this->assertSame(InvitationAccessTransferStatus::Approved, $transfer->status);
        $this->assertNull($transfer->current_slot);
        $this->assertSame($transfer->requested_credential_id, $invitation->fresh()->currentBrowserCredential->id);
        $this->assertSame(1, $invitation->browserCredentials()->where('current_slot', 'current')->count());
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertDatabaseCount('rsvp_submission_items', 0);

        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'First Guest');
        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->withUnencryptedCookie($pendingCookie, 'invalid-pending-secret')
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.trustState', 'claimed_elsewhere')
            ->assertJsonPath('data.privateInvitation.rsvp', null)
            ->assertDontSee('First Guest');
    }

    public function test_rejection_preserves_current_trust_revokes_pending_and_allows_new_request(): void
    {
        [$invitation, $trustedCookie, $trustedSecret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->postJson('/api/private-invitations/access-requests/reject', ['token' => $token])
            ->assertOk()->assertJsonPath('data.accessState', 'rejected');
        $transfer = InvitationAccessTransferRequest::query()->firstOrFail();
        $this->assertSame(InvitationAccessTransferStatus::Rejected, $transfer->status);
        $this->assertNotNull($transfer->requestedCredential->revoked_at);
        $this->assertSame($invitation->currentBrowserCredential->id, $invitation->fresh()->currentBrowserCredential->id);

        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->withUnencryptedCookie($trustedCookie, 'invalid-trusted-secret')
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.accessState', 'can_request')
            ->assertJsonPath('data.privateInvitation.rsvp', null);
        $this->postJson('/api/private-invitations/access-requests', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('invitation_access_transfer_requests', 2);
    }

    public function test_expiry_and_deactivation_retire_pending_proof_without_affecting_current_trust(): void
    {
        [$invitation, $trustedCookie, $trustedSecret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $this->travel(24)->hours();
        $this->travel(1)->second();

        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->postJson('/api/private-invitations/access-requests/approve', ['token' => $token])->assertUnprocessable();
        $this->assertSame(InvitationAccessTransferStatus::Expired, InvitationAccessTransferRequest::query()->first()->status);
        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->withUnencryptedCookie($trustedCookie, 'invalid-trusted-secret')
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.accessState', 'can_request');

        $this->withUnencryptedCookie($trustedCookie, 'invalid-trusted-secret')
            ->postJson('/api/private-invitations/access-requests', ['token' => $token])->assertOk();
        app(SetInvitationStatus::class)->handle($invitation->fresh(), InvitationStatus::Inactive);
        $latest = InvitationAccessTransferRequest::query()->latest('created_at')->first();
        $this->assertSame(InvitationAccessTransferStatus::Invalidated, $latest->status);
        $this->assertNotNull($latest->requestedCredential->revoked_at);
        $this->assertSame($invitation->currentBrowserCredential->id, $invitation->fresh()->currentBrowserCredential->id);
        $this->postJson('/api/private-invitations/access-requests', ['token' => $token])->assertUnprocessable();

        app(SetInvitationStatus::class)->handle($invitation->fresh(), InvitationStatus::Active);
        $this->postJson('/api/private-invitations/access-requests', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('invitation_access_transfer_requests', 3);
    }

    public function test_handoff_projection_and_transfer_activity_hard_delete_contract_are_private_safe(): void
    {
        [$invitation] = $this->claimedInvitation();
        $token = $invitation->currentPrivateLink->encrypted_token;
        $this->postJson('/api/private-invitations/site', ['token' => $token, 'handoffOnly' => true])
            ->assertOk()->assertJsonPath('data.privateInvitation.handoffOnly', true)
            ->assertJsonPath('data.privateInvitation.currentPath', '/i/'.$token)
            ->assertJsonPath('data.privateInvitation.rsvp', null)
            ->assertJsonPath('data.privateInvitation.accessRequest', null)
            ->assertDontSee('First Guest');

        [$fresh] = $this->claimedInvitation();
        $this->assertTrue($fresh->canPermanentlyDelete());
        $this->requestAccess($fresh);
        $this->assertFalse($fresh->fresh()->canPermanentlyDelete());
        $this->expectException(ValidationException::class);
        app(DeleteInvitation::class)->handle($fresh->fresh('guests'));
    }

    public function test_all_transfer_mutations_enforce_real_csrf_middleware(): void
    {
        [$invitation] = $this->claimedInvitation();
        $app = $this->app;
        $app->instance(ValidateCsrfToken::class, new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        foreach ([
            '/api/private-invitations/access-requests',
            '/api/private-invitations/access-requests/approve',
            '/api/private-invitations/access-requests/reject',
        ] as $path) {
            $this->postJson($path, ['token' => $invitation->currentPrivateLink->encrypted_token])->assertStatus(419);
        }
        $this->assertDatabaseCount('invitation_access_transfer_requests', 0);
    }

    /** @return array{Invitation, string, string} */
    private function claimedInvitation(): array
    {
        $event = Event::factory()->create();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'First', 'last_name' => 'Guest']]);
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$invitation->fresh('currentPrivateLink', 'currentBrowserCredential'), $cookie, $claim->getCookie($cookie, false)->getValue()];
    }

    /** @return array{string, string} */
    private function requestAccess(Invitation $invitation): array
    {
        $response = $this->withHeader('User-Agent', $this->chromeWindows())
            ->postJson('/api/private-invitations/access-requests', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = InvitationAccessTransferRequest::query()->latest('created_at')->firstOrFail()->requestedCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$cookie, $response->getCookie($cookie, false)->getValue()];
    }

    private function chromeWindows(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36';
    }
}
