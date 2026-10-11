<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\SetInvitationStatus;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Invitations\InvitationTrustCookie;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\InvitationAccessTransferRequest;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationTrustedAccessResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_owner_and_admin_can_reset_idempotently_while_auth_and_event_authorization_are_required(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        $admin = User::factory()->create();
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $outsider = User::factory()->create();
        $url = $this->resetUrl($event, $invitation);

        $this->deleteJson($url)->assertUnauthorized();
        $this->withUnencryptedCookie($cookie, $secret)->deleteJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->deleteJson($url)->assertForbidden();

        $this->actingAs($admin)->deleteJson($url)->assertOk()->assertExactJson(['data' => [
            'changed' => true, 'trustState' => 'unclaimed', 'hasPendingAccessRequest' => false,
        ]]);
        $this->assertNull($invitation->fresh()->currentBrowserCredential);
        $this->assertNotNull($invitation->browserCredentials()->sole()->revoked_at);

        $this->actingAs($owner)->deleteJson($url)->assertOk()->assertExactJson(['data' => [
            'changed' => false, 'trustState' => 'unclaimed', 'hasPendingAccessRequest' => false,
        ]]);
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }

    public function test_reset_invalidates_pending_transfer_and_removes_private_data_from_both_browsers(): void
    {
        [$event, $owner, $invitation, $trustedCookie, $trustedSecret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->actingAs($owner)->deleteJson($this->resetUrl($event, $invitation))
            ->assertOk()->assertJsonPath('data.changed', true);

        $transfer = InvitationAccessTransferRequest::query()->sole();
        $this->assertSame(InvitationAccessTransferStatus::Invalidated, $transfer->status);
        $this->assertNull($transfer->current_slot);
        $this->assertNotNull($transfer->requestedCredential->revoked_at);
        $this->assertSame(0, $invitation->browserCredentials()->where('current_slot', 'current')->count());
        $this->assertSame(0, $invitation->accessTransferRequests()->where('current_slot', 'active')->count());

        foreach ([[$trustedCookie, $trustedSecret], [$pendingCookie, $pendingSecret]] as [$cookie, $secret]) {
            $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', ['token' => $token])
                ->assertOk()
                ->assertJsonPath('data.privateInvitation.trustState', 'unclaimed')
                ->assertJsonPath('data.privateInvitation.canOpen', true)
                ->assertJsonPath('data.privateInvitation.rsvp', null)
                ->assertJsonPath('data.privateInvitation.accessRequest', null)
                ->assertDontSee('Reset Guest');
        }

        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->postJson('/api/private-invitations/access-requests/approve', ['token' => $token])->assertNotFound();
        $this->withUnencryptedCookie($trustedCookie, $trustedSecret)
            ->postJson('/api/private-invitations/access-requests/reject', ['token' => $token])->assertNotFound();
    }

    public function test_inactive_reset_preserves_lifecycle_and_reactivation_does_not_revive_access(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $token = $invitation->currentPrivateLink->encrypted_token;
        app(SetInvitationStatus::class)->handle($invitation, InvitationStatus::Inactive);

        $this->actingAs($owner)->deleteJson($this->resetUrl($event, $invitation))->assertOk();
        $this->assertSame(InvitationStatus::Inactive, $invitation->refresh()->status);
        $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.trustState', 'unclaimed')
            ->assertJsonPath('data.privateInvitation.canOpen', false)
            ->assertJsonPath('data.privateInvitation.rsvp', null);

        app(SetInvitationStatus::class)->handle($invitation, InvitationStatus::Active);
        $this->withUnencryptedCookie($pendingCookie, $pendingSecret)->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.privateInvitation.trustState', 'unclaimed')
            ->assertJsonPath('data.privateInvitation.canOpen', true);
        $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertOk()->assertJsonPath('data.claimedNow', true);
        $this->assertSame(1, $invitation->browserCredentials()->where('current_slot', 'current')->count());
    }

    public function test_reset_preserves_private_link_rsvp_history_and_meaningful_access_history(): void
    {
        [$event, $owner, $invitation] = $this->claimedInvitation();
        $guest = $invitation->guests()->sole();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$invitation->id}/rsvp", [
            'responses' => [['guestId' => $guest->id, 'response' => 'declined']],
        ])->assertOk();
        $path = $invitation->currentPrivateLink->path();
        $linkId = $invitation->currentPrivateLink->id;
        $lastResponse = $invitation->rsvpSubmissions()->max('created_at');
        $this->requestAccess($invitation);

        $this->actingAs($owner)->deleteJson($this->resetUrl($event, $invitation))->assertOk();

        $this->assertSame($path, $invitation->fresh()->currentPrivateLink->path());
        $this->assertSame($linkId, $invitation->fresh()->currentPrivateLink->id);
        $this->assertSame(1, $invitation->privateLinks()->count());
        $this->assertSame('declined', $guest->refresh()->rsvp_response->value);
        $this->assertSame(1, $invitation->rsvpSubmissions()->count());
        $this->assertSame(1, $invitation->rsvpSubmissions()->first()->items()->count());
        $this->assertSame($lastResponse, $invitation->rsvpSubmissions()->max('created_at'));
        $this->assertSame(1, $invitation->accessTransferRequests()->count());
        $this->assertFalse($invitation->fresh('guests')->canPermanentlyDelete());
    }

    public function test_management_read_models_expose_only_bounded_boolean_access_summary(): void
    {
        [$event, $owner, $invitation] = $this->claimedInvitation();
        $this->requestAccess($invitation);

        foreach ([
            "/api/events/{$event->id}/invitations",
            "/api/events/{$event->id}/invitations/{$invitation->id}",
        ] as $url) {
            $response = $this->actingAs($owner)->getJson($url)->assertOk();
            $root = str_ends_with($url, $invitation->id) ? 'data.trustedAccess' : 'data.0.trustedAccess';
            $response->assertJsonPath("{$root}.hasTrustedBrowser", true)
                ->assertJsonPath("{$root}.hasPendingAccessRequest", true)
                ->assertJsonCount(2, $root)
                ->assertDontSee('secret_hash')->assertDontSee('credentialId');
        }
    }

    /** @return array{Event, User, Invitation, string, string} */
    private function claimedInvitation(): array
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Reset', 'last_name' => 'Guest']]);
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$event, $owner, $invitation->fresh(['guests', 'currentPrivateLink']), $cookie, $claim->getCookie($cookie, false)->getValue()];
    }

    /** @return array{string, string} */
    private function requestAccess(Invitation $invitation): array
    {
        $response = $this->postJson('/api/private-invitations/access-requests', [
            'token' => $invitation->currentPrivateLink->encrypted_token,
        ])->assertOk();
        $credential = InvitationAccessTransferRequest::query()->latest('created_at')->firstOrFail()->requestedCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$cookie, $response->getCookie($cookie, false)->getValue()];
    }

    private function resetUrl(Event $event, Invitation $invitation): string
    {
        return "/api/events/{$event->id}/invitations/{$invitation->id}/trusted-access";
    }
}
