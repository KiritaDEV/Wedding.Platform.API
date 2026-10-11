<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\UpdateInvitationRsvp;
use App\Enums\GuestStatus;
use App\Enums\InvitationStatus;
use App\Enums\RsvpActorType;
use App\Invitations\InvitationTrustCookie;
use App\Invitations\PrivateInvitationToken;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationPrivateLink;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateInvitationRsvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_first_and_later_material_private_submissions_create_canonical_history_and_confirmation(): void
    {
        [$event, $invitation, $cookie, $secret] = $this->trustedInvitation(twoGuests: true);
        $guests = $invitation->guests;

        $first = $this->submit($invitation, $cookie, $secret, [
            ['guestId' => $guests[0]->id, 'response' => 'attending'],
            ['guestId' => $guests[1]->id, 'response' => 'declined'],
        ])->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.confirmation', 'received')
            ->assertJsonPath('data.rsvp.status', 'complete')
            ->assertJsonPath('data.rsvp.attendingCount', 1)
            ->assertJsonPath('data.rsvp.declinedCount', 1)
            ->assertJsonPath('data.rsvp.availability', 'open')
            ->assertJsonCount(2, 'data.rsvp.guests');
        $firstUpdated = $first->json('data.rsvp.lastUpdated');
        $this->assertNotNull($firstUpdated);
        $this->assertDatabaseHas('rsvp_submissions', [
            'invitation_id' => $invitation->id,
            'actor_type' => RsvpActorType::PrivateInvitation->value,
            'actor_user_id' => null,
            'actor_name_snapshot' => null,
            'note' => null,
        ]);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertDatabaseCount('rsvp_submission_items', 2);

        $this->travel(1)->second();
        $second = $this->submit($invitation, $cookie, $secret, [
            ['guestId' => $guests[0]->id, 'response' => 'declined'],
            ['guestId' => $guests[1]->id, 'response' => 'declined'],
        ])->assertOk()->assertJsonPath('data.confirmation', 'updated');
        $this->assertNotSame($firstUpdated, $second->json('data.rsvp.lastUpdated'));
        $this->assertDatabaseCount('rsvp_submissions', 2);
        $this->assertDatabaseCount('rsvp_submission_items', 4);
    }

    public function test_management_history_does_not_change_first_private_confirmation_and_noop_is_history_free(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedInvitation(twoGuests: true);
        $guests = $invitation->guests;
        app(UpdateInvitationRsvp::class)->handle($invitation, User::factory()->create(), [
            ['guest_id' => $guests[0]->id, 'response' => 'attending'],
            ['guest_id' => $guests[1]->id, 'response' => null],
        ], 'Management only');

        $payload = [
            ['guestId' => $guests[0]->id, 'response' => 'attending'],
            ['guestId' => $guests[1]->id, 'response' => 'declined'],
        ];
        $material = $this->submit($invitation, $cookie, $secret, $payload)
            ->assertOk()->assertJsonPath('data.confirmation', 'received');
        $lastUpdated = $material->json('data.rsvp.lastUpdated');
        $this->assertDatabaseCount('rsvp_submissions', 2);

        $this->travel(1)->second();
        $noop = $this->submit($invitation, $cookie, $secret, $payload)
            ->assertOk()->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.confirmation', null)
            ->assertJsonPath('data.rsvp.lastUpdated', $lastUpdated);
        $this->assertDatabaseCount('rsvp_submissions', 2);
        $this->assertDatabaseCount('rsvp_submission_items', 4);
        $noop->assertJsonMissingPath('data.history')->assertJsonMissingPath('data.note')->assertJsonMissingPath('data.actor');
    }

    public function test_exact_active_guest_set_and_answered_values_are_required_atomically(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedInvitation(twoGuests: true);
        $guests = $invitation->guests;
        $valid = [
            ['guestId' => $guests[0]->id, 'response' => 'attending'],
            ['guestId' => $guests[1]->id, 'response' => 'declined'],
        ];

        $invalidPayloads = [
            [$valid[0]],
            [$valid[0], $valid[0]],
            [['guestId' => $guests[0]->id, 'response' => null], $valid[1]],
            [['guestId' => $guests[0]->id, 'response' => 'pending'], $valid[1]],
            [['guestId' => User::factory()->create()->id, 'response' => 'attending'], $valid[1]],
        ];
        foreach ($invalidPayloads as $payload) {
            $this->submit($invitation, $cookie, $secret, $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertNull($guests[0]->refresh()->rsvp_response);
        $this->assertNull($guests[1]->refresh()->rsvp_response);

        $guests[1]->update(['status' => GuestStatus::Inactive]);
        $this->submit($invitation, $cookie, $secret, $valid)->assertUnprocessable();
        $this->assertDatabaseCount('rsvp_submissions', 0);
    }

    public function test_roster_changes_reject_stale_payload_and_partial_household_can_complete(): void
    {
        [$event, $invitation, $cookie, $secret] = $this->trustedInvitation();
        $first = $invitation->guests->first();
        app(UpdateInvitationRsvp::class)->handle($invitation, User::factory()->create(), [
            ['guest_id' => $first->id, 'response' => 'attending'],
        ], null);
        $added = $invitation->guests()->create([
            'event_id' => $event->id,
            'first_name' => 'Added',
            'last_name' => 'Guest',
            'relationship' => 'friend',
            'side' => 'groom',
            'status' => GuestStatus::Active,
        ]);

        $this->submit($invitation, $cookie, $secret, [['guestId' => $first->id, 'response' => 'attending']])
            ->assertUnprocessable();
        $this->assertNull($added->refresh()->rsvp_response);
        $this->assertDatabaseCount('rsvp_submissions', 1);

        $this->submit($invitation, $cookie, $secret, [
            ['guestId' => $first->id, 'response' => 'attending'],
            ['guestId' => $added->id, 'response' => 'declined'],
        ])->assertOk()->assertJsonPath('data.rsvp.status', 'complete');
        $this->assertDatabaseCount('rsvp_submissions', 2);
    }

    public function test_availability_is_revalidated_while_trusted_read_remains_available(): void
    {
        [$event, $invitation, $cookie, $secret] = $this->trustedInvitation();
        $guest = $invitation->guests->first();
        $payload = [['guestId' => $guest->id, 'response' => 'attending']];

        $event->update(['rsvp_is_open' => false]);
        $this->submit($invitation, $cookie, $secret, $payload)->assertUnprocessable();
        $this->trustedSite($invitation, $cookie, $secret)->assertJsonPath('data.privateInvitation.rsvp.availability', 'event_closed');

        $event->update(['rsvp_is_open' => true, 'rsvp_deadline' => now()->subDay()->toDateString(), 'time_zone' => 'Asia/Manila']);
        $this->submit($invitation, $cookie, $secret, $payload)->assertUnprocessable();
        $this->trustedSite($invitation, $cookie, $secret)->assertJsonPath('data.privateInvitation.rsvp.availability', 'deadline_passed');

        $event->update(['rsvp_deadline' => null]);
        $invitation->update(['status' => InvitationStatus::Inactive]);
        $this->submit($invitation, $cookie, $secret, $payload)->assertUnprocessable();
        $this->trustedSite($invitation, $cookie, $secret)->assertJsonPath('data.privateInvitation.rsvp.availability', 'invitation_inactive');
        $this->assertDatabaseCount('rsvp_submissions', 0);
    }

    public function test_untrusted_unknown_and_management_authenticated_requests_cannot_mutate_or_leak(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedInvitation();
        $guest = $invitation->guests->first();
        $payload = [['guestId' => $guest->id, 'response' => 'attending']];

        $this->postJson('/api/private-invitations/rsvp', ['token' => $invitation->currentPrivateLink->encrypted_token, 'responses' => $payload])->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/private-invitations/rsvp', ['token' => $invitation->currentPrivateLink->encrypted_token, 'responses' => $payload])->assertNotFound();
        $this->withUnencryptedCookie($cookie, 'invalid-secret')
            ->postJson('/api/private-invitations/rsvp', ['token' => $invitation->currentPrivateLink->encrypted_token, 'responses' => $payload])->assertNotFound();
        $this->postJson('/api/private-invitations/rsvp', ['token' => PrivateInvitationToken::generate(), 'responses' => $payload])->assertNotFound();

        $old = $invitation->currentPrivateLink;
        $old->forceFill(['current_slot' => null, 'retired_at' => now()])->save();
        $newToken = PrivateInvitationToken::generate();
        InvitationPrivateLink::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'token_hash' => PrivateInvitationToken::hash($newToken),
            'encrypted_token' => $newToken,
            'current_slot' => 'current',
        ]);
        $this->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/rsvp', ['token' => $old->encrypted_token, 'responses' => $payload])
            ->assertNotFound();
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertNull($guest->refresh()->rsvp_response);
    }

    public function test_private_rsvp_route_enforces_real_csrf_before_mutation(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedInvitation();
        $guest = $invitation->guests->first();
        $payload = [
            'token' => $invitation->currentPrivateLink->encrypted_token,
            'responses' => [['guestId' => $guest->id, 'response' => 'attending']],
        ];
        $app = $this->app;
        $app->instance(ValidateCsrfToken::class, new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/rsvp', $payload)->assertStatus(419);
        $this->assertDatabaseCount('rsvp_submissions', 0);

        $this->withSession(['_token' => 'same-site-proof'])
            ->withHeader('X-CSRF-TOKEN', 'same-site-proof')
            ->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/rsvp', $payload)
            ->assertOk()->assertJsonPath('data.changed', true);
        $this->assertDatabaseCount('rsvp_submissions', 1);
    }

    public function test_competing_identical_and_conflicting_retries_converge_without_duplicate_or_partial_history(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedInvitation(twoGuests: true);
        $guests = $invitation->guests;
        $first = [
            ['guestId' => $guests[0]->id, 'response' => 'attending'],
            ['guestId' => $guests[1]->id, 'response' => 'declined'],
        ];
        $second = [
            ['guestId' => $guests[0]->id, 'response' => 'declined'],
            ['guestId' => $guests[1]->id, 'response' => 'attending'],
        ];

        $this->submit($invitation, $cookie, $secret, $first)->assertJsonPath('data.changed', true);
        $this->submit($invitation, $cookie, $secret, $first)->assertJsonPath('data.changed', false);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertDatabaseCount('rsvp_submission_items', 2);

        $this->submit($invitation, $cookie, $secret, $second)
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.confirmation', 'updated')
            ->assertJsonPath('data.rsvp.guests.0.response', 'declined')
            ->assertJsonPath('data.rsvp.guests.1.response', 'attending');
        $this->assertDatabaseCount('rsvp_submissions', 2);
        $this->assertDatabaseCount('rsvp_submission_items', 4);
    }

    /** @return array{Event, Invitation, string, string} */
    private function trustedInvitation(bool $twoGuests = false): array
    {
        $event = Event::factory()->create(['rsvp_is_open' => true, 'rsvp_deadline' => null, 'time_zone' => 'Asia/Manila']);
        $guests = [['first_name' => 'First', 'last_name' => 'Guest']];
        if ($twoGuests) {
            $guests[] = ['first_name' => 'Second', 'last_name' => 'Guest'];
        }
        $invitation = app(CreateInvitation::class)->handle($event, $guests);
        $response = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$event, $invitation->fresh('guests', 'currentPrivateLink'), $cookie, $response->getCookie($cookie, false)->getValue()];
    }

    private function submit($invitation, string $cookie, string $secret, array $responses)
    {
        return $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/rsvp', [
            'token' => $invitation->currentPrivateLink->encrypted_token,
            'responses' => $responses,
        ]);
    }

    private function trustedSite($invitation, string $cookie, string $secret)
    {
        return $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/site', [
            'token' => $invitation->currentPrivateLink->encrypted_token,
        ])->assertOk();
    }
}
