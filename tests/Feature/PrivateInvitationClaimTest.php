<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\UpdateInvitationRsvp;
use App\Actions\Websites\PublishWebsite;
use App\Enums\InvitationStatus;
use App\Invitations\BrowserCredentialSecret;
use App\Invitations\InvitationTrustCookie;
use App\Invitations\PrivateInvitationToken;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationPrivateLink;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivateInvitationClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_context_is_repeatable_side_effect_free_and_unknown_tokens_are_generic_not_found(): void
    {
        $invitation = Invitation::factory()->create();
        $token = $invitation->currentPrivateLink->encrypted_token;

        foreach (range(1, 2) as $_) {
            $this->postJson('/api/private-invitations/context', ['token' => $token])
                ->assertOk()->assertExactJson(['data' => [
                    'linkStatus' => 'current',
                    'invitationStatus' => 'active',
                    'trustState' => 'unclaimed',
                    'canOpen' => true,
                ]]);
        }

        $this->assertDatabaseCount('invitation_browser_credentials', 0);
        $this->postJson('/api/private-invitations/context', ['token' => PrivateInvitationToken::generate()])->assertNotFound();
        $this->postJson('/api/private-invitations/open', ['token' => PrivateInvitationToken::generate()])->assertNotFound();
        $this->assertDatabaseCount('invitation_browser_credentials', 0);
    }

    public function test_first_browser_claims_idempotently_while_a_different_browser_cannot_replace_it(): void
    {
        $invitation = Invitation::factory()->create();
        $token = $invitation->currentPrivateLink->encrypted_token;

        $first = $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertOk()->assertExactJson(['data' => [
                'trustState' => 'trusted', 'claimedNow' => true, 'canOpen' => true,
            ]]);
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookieName = app(InvitationTrustCookie::class)->name($credential);
        $secret = $first->getCookie($cookieName, false)->getValue();

        $this->assertSame(43, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
        $this->assertSame(BrowserCredentialSecret::hash($secret), $credential->secret_hash);
        $this->assertNotSame($token, $secret);
        $this->assertStringNotContainsString($secret, json_encode($first->json(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, json_encode((array) DB::table('invitation_browser_credentials')->first(), JSON_THROW_ON_ERROR));
        $cookie = $first->getCookie($cookieName, false);
        $expectedLifetimeSeconds = 60 * 60 * 24 * 365 * 5;
        $this->assertGreaterThan(time() + $expectedLifetimeSeconds - 10, $cookie->getExpiresTime());
        $this->assertLessThanOrEqual(time() + $expectedLifetimeSeconds, $cookie->getExpiresTime());
        $this->assertGreaterThan(0, $cookie->getMaxAge());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(config('session.secure') ?? app()->isProduction(), $cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('', $cookie->getDomain());
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('invitation_browser_credentials', 'expires_at'));

        $this->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.trustState', 'claimed_elsewhere')->assertJsonPath('data.canOpen', false);
        $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertConflict()->assertJsonPath('data.trustState', 'claimed_elsewhere')
            ->assertJsonPath('data.claimedNow', false)->assertCookieMissing($cookieName);
        $this->assertDatabaseCount('invitation_browser_credentials', 1);

        $this->withUnencryptedCookie($cookieName, $secret)
            ->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertOk()->assertJsonPath('data.claimedNow', false)->assertCookieMissing($cookieName);
        $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
        $this->assertNull($credential->fresh()->revoked_at);
    }

    public function test_inactive_invitation_cannot_be_claimed_but_preserves_existing_trust_through_lifecycle(): void
    {
        $invitation = Invitation::factory()->inactive()->create();
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.invitationStatus', 'inactive')->assertJsonPath('data.canOpen', false);
        $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertConflict()->assertJsonPath('data.trustState', 'unclaimed');
        $this->assertDatabaseCount('invitation_browser_credentials', 0);

        $invitation->update(['status' => InvitationStatus::Active]);
        $response = $this->postJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $name = app(InvitationTrustCookie::class)->name($credential);
        $secret = $response->getCookie($name, false)->getValue();
        $invitation->update(['status' => InvitationStatus::Inactive]);

        $this->withUnencryptedCookie($name, $secret)
            ->postJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $invitation->update(['status' => InvitationStatus::Active]);
        $this->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }

    public function test_claim_is_independent_of_event_rsvp_availability_and_has_no_rsvp_side_effects(): void
    {
        $event = Event::factory()->create([
            'rsvp_is_open' => false,
            'rsvp_deadline' => now()->subDay()->toDateString(),
        ]);
        $invitation = Invitation::factory()->for($event)->create();

        $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');

        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertDatabaseCount('rsvp_submission_items', 0);
        $this->assertDatabaseMissing('guests', ['rsvp_response' => 'attending']);
        $this->assertDatabaseMissing('guests', ['rsvp_response' => 'declined']);
    }

    public function test_pending_partial_and_complete_rsvp_states_do_not_affect_claim_eligibility(): void
    {
        $event = Event::factory()->create();
        $states = [
            [['first_name' => 'Pending Guest', 'rsvp_response' => null]],
            [['first_name' => 'Partial One', 'rsvp_response' => 'attending'], ['first_name' => 'Partial Two', 'rsvp_response' => null]],
            [['first_name' => 'Complete Guest', 'rsvp_response' => 'declined']],
        ];

        foreach ($states as $guests) {
            $invitation = app(CreateInvitation::class)->handle($event, array_map(
                fn (array $guest): array => ['first_name' => $guest['first_name']],
                $guests,
            ));
            foreach ($invitation->guests as $index => $guest) {
                $guest->update(['rsvp_response' => $guests[$index]['rsvp_response']]);
            }

            $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])
                ->assertOk()->assertJsonPath('data.claimedNow', true);
        }

        $this->assertDatabaseCount('invitation_browser_credentials', 3);
    }

    public function test_competing_first_claim_attempts_converge_on_one_current_credential(): void
    {
        $invitation = Invitation::factory()->create();
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertOk()->assertJsonPath('data.claimedNow', true);
        $credentialId = $invitation->fresh()->currentBrowserCredential->id;
        $this->postJson('/api/private-invitations/open', ['token' => $token])
            ->assertConflict()->assertJsonPath('data.trustState', 'claimed_elsewhere');

        $this->assertDatabaseCount('invitation_browser_credentials', 1);
        $this->assertDatabaseHas('invitation_browser_credentials', [
            'id' => $credentialId,
            'current_slot' => 'current',
            'revoked_at' => null,
        ]);
    }

    public function test_historical_link_is_hidden_from_untrusted_browser_but_normalizes_for_current_trusted_browser(): void
    {
        $invitation = Invitation::factory()->create();
        $old = $invitation->currentPrivateLink;
        $oldToken = $old->encrypted_token;
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $oldToken])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookieName = app(InvitationTrustCookie::class)->name($credential);
        $secret = $claim->getCookie($cookieName, false)->getValue();

        $old->forceFill(['current_slot' => null, 'retired_at' => now()])->save();
        $newToken = PrivateInvitationToken::generate();
        $new = InvitationPrivateLink::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'token_hash' => PrivateInvitationToken::hash($newToken),
            'encrypted_token' => $newToken,
            'current_slot' => 'current',
        ]);

        $this->postJson('/api/private-invitations/context', ['token' => $oldToken])->assertNotFound();
        $this->postJson('/api/private-invitations/open', ['token' => $oldToken])->assertNotFound();
        $this->withUnencryptedCookie($cookieName, $secret)->withHeader('Origin', config('app.url'))
            ->postJson('/api/private-invitations/context', ['token' => $oldToken])
            ->assertOk()->assertExactJson(['data' => [
                'linkStatus' => 'historical',
                'invitationStatus' => 'active',
                'trustState' => 'trusted',
                'canOpen' => true,
                'currentPath' => $new->path(),
            ]]);
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }

    public function test_one_browser_can_hold_independent_credentials_for_multiple_invitations(): void
    {
        $firstInvitation = Invitation::factory()->create();
        $secondInvitation = Invitation::factory()->create();
        $firstResponse = $this->postJson('/api/private-invitations/open', ['token' => $firstInvitation->currentPrivateLink->encrypted_token]);
        $firstCredential = $firstInvitation->fresh()->currentBrowserCredential;
        $firstName = app(InvitationTrustCookie::class)->name($firstCredential);
        $firstSecret = $firstResponse->getCookie($firstName, false)->getValue();

        $secondResponse = $this->withUnencryptedCookie($firstName, $firstSecret)
            ->postJson('/api/private-invitations/open', ['token' => $secondInvitation->currentPrivateLink->encrypted_token]);
        $secondCredential = $secondInvitation->fresh()->currentBrowserCredential;
        $secondName = app(InvitationTrustCookie::class)->name($secondCredential);
        $secondSecret = $secondResponse->getCookie($secondName, false)->getValue();

        $this->assertNotSame($firstName, $secondName);
        $this->withUnencryptedCookie($secondName, $secondSecret)->withHeader('Origin', config('app.url'))
            ->postJson('/api/private-invitations/context', ['token' => $firstInvitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $this->postJson('/api/private-invitations/context', ['token' => $secondInvitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $this->assertDatabaseCount('invitation_browser_credentials', 2);
    }

    public function test_encrypted_http_only_cookie_round_trips_through_the_stateful_spa_middleware(): void
    {
        $this->withMiddleware(EncryptCookies::class);
        $invitation = Invitation::factory()->create();
        $token = $invitation->currentPrivateLink->encrypted_token;
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $name = app(InvitationTrustCookie::class)->name($credential);
        $browserStoredValue = $claim->getCookie($name, false)->getValue();

        $this->withUnencryptedCookie($name, $browserStoredValue)
            ->withHeader('Origin', 'http://'.config('sanctum.stateful.0'))
            ->postJson('/api/private-invitations/context', ['token' => $token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
    }

    public function test_trust_survives_guest_rsvp_and_publication_changes(): void
    {
        $event = Event::factory()->create();
        $actor = User::factory()->create();
        $invitation = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana'], ['first_name' => 'Pedro'],
        ]);
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token]);
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookieName = app(InvitationTrustCookie::class)->name($credential);
        $secret = $claim->getCookie($cookieName, false)->getValue();

        $invitation->guests()->first()->update(['first_name' => 'Ana Maria']);
        app(UpdateInvitationRsvp::class)->handle($invitation, $actor, $invitation->guests()->get()->map(
            fn ($guest): array => ['guest_id' => $guest->id, 'response' => 'attending'],
        )->all(), null);
        $firstWebsite = Website::factory()->for($event)->create();
        $secondWebsite = Website::factory()->for($event)->create(['name' => 'Second']);
        app(PublishWebsite::class)->handle($event, $firstWebsite);
        app(PublishWebsite::class)->handle($event->refresh(), $secondWebsite);

        $this->withUnencryptedCookie($cookieName, $secret)
            ->postJson('/api/private-invitations/context', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.trustState', 'trusted');
        $this->assertSame($credential->id, $invitation->fresh()->currentBrowserCredential->id);
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
        $this->assertDatabaseCount('rsvp_submissions', 1);
    }

    public function test_open_route_enforces_real_csrf_middleware_without_creating_trust(): void
    {
        $invitation = Invitation::factory()->create();
        $app = $this->app;
        $encrypter = $app->make(Encrypter::class);
        $app->instance(ValidateCsrfToken::class, new class($app, $encrypter) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertStatus(419);
        $this->assertDatabaseCount('invitation_browser_credentials', 0);

        $this->withSession(['_token' => 'same-site-proof'])
            ->withHeader('X-CSRF-TOKEN', 'same-site-proof')
            ->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.claimedNow', true);
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }
}
