<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\DeleteInvitation;
use App\Actions\Invitations\ProvisionPrivateInvitationLink;
use App\Actions\Invitations\ResetInvitationTrustedAccess;
use App\Actions\Invitations\UpdateInvitationRsvp;
use App\Actions\Websites\PublishWebsite;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationAccessTransferStatus;
use App\Enums\InvitationStatus;
use App\Invitations\InvitationTrustCookie;
use App\Invitations\PrivateInvitationToken;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\InvitationAccessTransferRequest;
use App\Models\InvitationPrivateLink;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PrivateInvitationLinkRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_owner_and_admin_can_rotate_but_authentication_and_event_authorization_are_required(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        $admin = User::factory()->create();
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $outsider = User::factory()->create();
        $url = $this->rotateUrl($event, $invitation);

        $this->postJson($url)->assertUnauthorized();
        $this->withUnencryptedCookie($cookie, $secret)->postJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->postJson($url)->assertForbidden();
        $this->actingAs($admin)->postJson($url)->assertOk();
        $firstAdminPath = $invitation->fresh()->currentPrivateLink->path();
        $this->actingAs($owner)->postJson($url)->assertOk();
        $this->assertNotSame($firstAdminPath, $invitation->fresh()->currentPrivateLink->path());
        $this->assertSame(3, $invitation->privateLinks()->count());
        $this->assertSame(1, $invitation->privateLinks()->where('current_slot', 'current')->count());
    }

    public function test_rotation_retires_old_link_and_returns_only_secure_new_canonical_path(): void
    {
        [$event, $owner, $invitation] = $this->managedInvitation();
        $old = $invitation->currentPrivateLink;
        $oldToken = $old->encrypted_token;

        $response = $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        $new = $invitation->fresh()->currentPrivateLink;
        $newToken = $new->encrypted_token;

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(43, strlen($newToken));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $newToken);
        $this->assertSame(PrivateInvitationToken::hash($newToken), $new->token_hash);
        $this->assertNotSame($newToken, $new->getRawOriginal('encrypted_token'));
        $this->assertNull($old->refresh()->current_slot);
        $this->assertNotNull($old->retired_at);
        $this->assertSame('/i/'.$newToken, $response->json('data.privateInvitation.path'));
        $response->assertJsonMissingPath('data.oldPath')->assertJsonMissingPath('data.tokenHash')
            ->assertJsonMissingPath('data.encryptedToken')->assertJsonMissingPath('data.credentialId');
        $this->assertStringNotContainsString($oldToken, $response->getContent());
    }

    public function test_rotation_preserves_current_trust_and_trusted_historical_link_normalizes_privately(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        $oldToken = $invitation->currentPrivateLink->encrypted_token;
        $credential = $invitation->currentBrowserCredential;
        $credentialCount = $invitation->browserCredentials()->count();

        $response = $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        $newPath = $response->json('data.privateInvitation.path');
        $newToken = substr($newPath, 3);

        $this->assertSame($credential->id, $invitation->fresh()->currentBrowserCredential->id);
        $this->assertNull($credential->refresh()->revoked_at);
        $this->assertSame($credentialCount, $invitation->browserCredentials()->count());
        $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', ['token' => $newToken])
            ->assertOk()->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.linkStatus', 'current')
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Rotation Guest');

        $this->withUnencryptedCookie($cookie, 'invalid-secret')->postJson('/api/private-invitations/site', ['token' => $oldToken])
            ->assertNotFound()->assertJsonMissingPath('data.privateInvitation.currentPath')->assertDontSee($newToken);
        $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', ['token' => $oldToken])
            ->assertOk()->assertJsonPath('data.privateInvitation.linkStatus', 'historical')
            ->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.currentPath', $newPath)
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Rotation Guest');
        $this->assertSame($credentialCount, $invitation->browserCredentials()->count());
    }

    public function test_rotation_invalidates_pending_transfer_but_preserves_current_credential(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        [$pendingCookie, $pendingSecret] = $this->requestAccess($invitation);
        $oldToken = $invitation->currentPrivateLink->encrypted_token;
        $currentId = $invitation->currentBrowserCredential->id;

        $response = $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk()
            ->assertJsonPath('data.trustedAccess.hasTrustedBrowser', true)
            ->assertJsonPath('data.trustedAccess.hasPendingAccessRequest', false);
        $newToken = substr($response->json('data.privateInvitation.path'), 3);
        $transfer = InvitationAccessTransferRequest::query()->sole();

        $this->assertSame(InvitationAccessTransferStatus::Invalidated, $transfer->status);
        $this->assertNull($transfer->current_slot);
        $this->assertNotNull($transfer->requestedCredential->revoked_at);
        $this->assertSame($currentId, $invitation->fresh()->currentBrowserCredential->id);
        $this->withUnencryptedCookie($cookie, 'invalid-current-secret')->withUnencryptedCookie($pendingCookie, $pendingSecret)
            ->postJson('/api/private-invitations/site', ['token' => $oldToken])->assertNotFound()->assertDontSee($newToken);
        $this->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/access-requests/approve', ['token' => $oldToken])->assertNotFound();
        $this->withUnencryptedCookie($cookie, 'invalid-current-secret')->withUnencryptedCookie($pendingCookie, 'invalid-pending-secret')
            ->postJson('/api/private-invitations/access-requests', ['token' => $oldToken])->assertNotFound();
        $this->withUnencryptedCookie($cookie, 'invalid-current-secret')->withUnencryptedCookie($pendingCookie, 'invalid-pending-secret')
            ->postJson('/api/private-invitations/access-requests', ['token' => $newToken])->assertOk();
        $this->assertDatabaseCount('invitation_access_transfer_requests', 2);
    }

    public function test_active_and_inactive_unclaimed_rotation_preserve_lifecycle_and_open_rules(): void
    {
        foreach ([InvitationStatus::Active, InvitationStatus::Inactive] as $status) {
            [$event, $owner, $invitation] = $this->managedInvitation($status);
            $response = $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
            $token = substr($response->json('data.privateInvitation.path'), 3);

            $this->assertSame($status, $invitation->refresh()->status);
            $this->postJson('/api/private-invitations/context', ['token' => $token])
                ->assertJsonPath('data.trustState', 'unclaimed')
                ->assertJsonPath('data.canOpen', $status === InvitationStatus::Active);
            $open = $this->postJson('/api/private-invitations/open', ['token' => $token]);
            $status === InvitationStatus::Active ? $open->assertOk() : $open->assertConflict();
        }
    }

    public function test_inactive_trusted_rotation_preserves_read_only_trust_and_normalizes_old_link(): void
    {
        [$event, $owner, $invitation, $cookie, $secret] = $this->claimedInvitation();
        $oldToken = $invitation->currentPrivateLink->encrypted_token;
        $credentialId = $invitation->currentBrowserCredential->id;
        $invitation->update(['status' => InvitationStatus::Inactive]);

        $response = $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        $newPath = $response->json('data.privateInvitation.path');
        $this->assertSame(InvitationStatus::Inactive, $invitation->refresh()->status);
        $this->assertSame($credentialId, $invitation->currentBrowserCredential->id);
        $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', ['token' => $oldToken])
            ->assertOk()->assertJsonPath('data.privateInvitation.linkStatus', 'historical')
            ->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.currentPath', $newPath)
            ->assertJsonPath('data.privateInvitation.rsvp.availability', 'invitation_inactive');
    }

    public function test_rotation_preserves_material_rsvp_history_and_published_website_when_availability_is_closed(): void
    {
        [$event, $owner, $invitation] = $this->managedInvitation();
        $website = $this->initializeWebsite($event);
        app(PublishWebsite::class)->handle($event, $website);
        $guest = $invitation->guests()->sole();
        app(UpdateInvitationRsvp::class)->handle($invitation, $owner, [
            ['guest_id' => $guest->id, 'response' => 'attending'],
        ], null);
        $event->update(['rsvp_is_open' => false, 'rsvp_deadline' => now()->subDay()->toDateString()]);
        $lastResponse = $invitation->rsvpSubmissions()->max('created_at');

        $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();

        $this->assertSame('attending', $guest->refresh()->rsvp_response->value);
        $this->assertSame(1, $invitation->rsvpSubmissions()->count());
        $this->assertSame(1, $invitation->rsvpSubmissions()->first()->items()->count());
        $this->assertSame($lastResponse, $invitation->rsvpSubmissions()->max('created_at'));
        $this->assertSame($website->id, $event->refresh()->published_website_id);
        $this->assertFalse($event->rsvp_is_open);
    }

    public function test_rotate_and_reset_orderings_remain_coherent(): void
    {
        [$event, $owner, $invitation] = $this->claimedInvitation();

        app(ResetInvitationTrustedAccess::class)->handle($event, $invitation);
        $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        $this->assertNull($invitation->fresh()->currentBrowserCredential);
        $this->assertSame(1, $invitation->privateLinks()->where('current_slot', 'current')->count());

        $this->postJson('/api/private-invitations/open', ['token' => $invitation->fresh()->currentPrivateLink->encrypted_token])->assertOk();
        $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        app(ResetInvitationTrustedAccess::class)->handle($event, $invitation);
        $this->assertNull($invitation->fresh()->currentBrowserCredential);
        $this->assertSame(1, $invitation->privateLinks()->where('current_slot', 'current')->count());
    }

    public function test_rotation_preserves_rsvp_roster_event_and_hard_delete_semantics(): void
    {
        [$event, $owner, $invitation] = $this->managedInvitation();
        $guest = $invitation->guests()->sole();
        $guestSnapshot = $guest->only(['id', 'first_name', 'last_name', 'status', 'relationship', 'side']);
        $eventSnapshot = $event->only(['slug', 'published_website_id']);

        $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertOk();
        $this->assertSame($guestSnapshot, $guest->refresh()->only(array_keys($guestSnapshot)));
        $this->assertSame($eventSnapshot, $event->refresh()->only(array_keys($eventSnapshot)));
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertDatabaseCount('rsvp_submission_items', 0);
        $this->assertTrue($invitation->fresh('guests')->canPermanentlyDelete());
        app(DeleteInvitation::class)->handle($invitation->fresh('guests'));
        $this->assertSame(0, InvitationPrivateLink::query()->where('invitation_id', $invitation->id)->count());
    }

    public function test_provisioning_failure_rolls_back_link_trust_and_pending_transfer_state(): void
    {
        [$event, $owner, $invitation] = $this->claimedInvitation();
        $this->requestAccess($invitation);
        $old = $invitation->currentPrivateLink;
        $credential = $invitation->currentBrowserCredential;
        $transfer = InvitationAccessTransferRequest::query()->sole();
        $this->app->bind(ProvisionPrivateInvitationLink::class, fn () => new class extends ProvisionPrivateInvitationLink
        {
            public function handle(Invitation $invitation): InvitationPrivateLink
            {
                throw new RuntimeException('Forced provisioning failure.');
            }
        });

        $this->actingAs($owner)->postJson($this->rotateUrl($event, $invitation))->assertServerError();

        $this->assertSame('current', $old->refresh()->current_slot);
        $this->assertNull($old->retired_at);
        $this->assertSame(1, $invitation->privateLinks()->count());
        $this->assertSame($credential->id, $invitation->fresh()->currentBrowserCredential->id);
        $this->assertNull($credential->refresh()->revoked_at);
        $this->assertSame(InvitationAccessTransferStatus::Pending, $transfer->refresh()->status);
        $this->assertSame('active', $transfer->current_slot);
        $this->assertNull($transfer->requestedCredential->revoked_at);
    }

    /** @return array{Event, User, Invitation} */
    private function managedInvitation(InvitationStatus $status = InvitationStatus::Active): array
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Rotation', 'last_name' => 'Guest']]);
        if ($status !== InvitationStatus::Active) {
            $invitation->update(['status' => $status]);
        }

        return [$event, $owner, $invitation->fresh(['guests', 'currentPrivateLink'])];
    }

    /** @return array{Event, User, Invitation, string, string} */
    private function claimedInvitation(): array
    {
        [$event, $owner, $invitation] = $this->managedInvitation();
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$event, $owner, $invitation->fresh(['guests', 'currentPrivateLink', 'currentBrowserCredential']), $cookie, $claim->getCookie($cookie, false)->getValue()];
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

    private function rotateUrl(Event $event, Invitation $invitation): string
    {
        return "/api/events/{$event->id}/invitations/{$invitation->id}/private-link/rotate";
    }
}
