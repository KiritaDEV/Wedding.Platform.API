<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\UpdateInvitationRsvp;
use App\Actions\Websites\PublishWebsite;
use App\Enums\GuestStatus;
use App\Enums\InvitationStatus;
use App\Invitations\InvitationTrustCookie;
use App\Invitations\PrivateInvitationToken;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationPrivateLink;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateInvitationSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_active_unclaimed_site_returns_published_website_with_rsvp_but_no_private_roster(): void
    {
        [$event, $invitation] = $this->publishedInvitation();
        $token = $invitation->currentPrivateLink->encrypted_token;

        foreach (range(1, 2) as $_) {
            $response = $this->postJson('/api/private-invitations/site', ['token' => $token])
                ->assertOk()
                ->assertJsonPath('data.status', 'published')
                ->assertJsonPath('data.event.id', $event->id)
                ->assertJsonPath('data.privateInvitation.trustState', 'unclaimed')
                ->assertJsonPath('data.privateInvitation.canOpen', true)
                ->assertJsonPath('data.privateInvitation.rsvp', null);
            $this->assertContains('rsvp', collect($response->json('data.website.sections'))->pluck('type')->all());
            $this->assertStringNotContainsString('Ana Secret', $response->getContent());
            $this->assertStringNotContainsString($invitation->guests->first()->id, $response->getContent());
        }

        $this->assertDatabaseCount('invitation_browser_credentials', 0);
        $this->assertDatabaseCount('rsvp_submissions', 0);
    }

    public function test_trusted_site_returns_only_active_current_rsvp_state_and_latest_material_timestamp(): void
    {
        [$event, $invitation] = $this->publishedInvitation(twoGuests: true);
        $actor = User::factory()->create();
        $guests = $invitation->guests;
        app(UpdateInvitationRsvp::class)->handle($invitation, $actor, [
            ['guest_id' => $guests[0]->id, 'response' => 'attending'],
            ['guest_id' => $guests[1]->id, 'response' => null],
        ], null);
        $lastUpdated = CarbonImmutable::parse($invitation->rsvpSubmissions()->latest('created_at')->value('created_at'))->toISOString();
        $guests[1]->update(['status' => GuestStatus::Inactive]);
        [$cookie, $secret] = $this->claim($invitation);

        $response = $this->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/site', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()
            ->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.rsvp.status', 'complete')
            ->assertJsonPath('data.privateInvitation.rsvp.attendingCount', 1)
            ->assertJsonPath('data.privateInvitation.rsvp.pendingCount', 0)
            ->assertJsonPath('data.privateInvitation.rsvp.lastUpdated', $lastUpdated)
            ->assertJsonPath('data.privateInvitation.rsvp.availability', 'event_closed')
            ->assertJsonCount(1, 'data.privateInvitation.rsvp.guests')
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Ana Secret')
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.response', 'attending');

        foreach (['relationship', 'side', 'weddingRoles', 'history', 'note', 'actor'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
        $this->assertStringNotContainsString('Pedro Hidden', $response->getContent());
    }

    public function test_trusted_availability_distinguishes_deadline_and_inactive_invitation(): void
    {
        [$event, $invitation] = $this->publishedInvitation();
        $event->update(['rsvp_is_open' => true, 'rsvp_deadline' => now()->subDay()->toDateString(), 'time_zone' => 'Asia/Manila']);
        [$cookie, $secret] = $this->claim($invitation);
        $this->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/site', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.privateInvitation.rsvp.availability', 'deadline_passed');

        $invitation->update(['status' => InvitationStatus::Inactive]);
        $this->postJson('/api/private-invitations/site', ['token' => $invitation->currentPrivateLink->encrypted_token])
            ->assertOk()->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.rsvp.availability', 'invitation_inactive')
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Ana Secret');
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }

    public function test_untrusted_inactive_claimed_elsewhere_unknown_and_historical_links_never_expose_roster(): void
    {
        [, $invitation] = $this->publishedInvitation();
        $token = $invitation->currentPrivateLink->encrypted_token;
        $invitation->update(['status' => InvitationStatus::Inactive]);
        $this->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertOk()->assertJsonPath('data.privateInvitation.canOpen', false)
            ->assertJsonPath('data.privateInvitation.rsvp', null)
            ->assertDontSee('Ana Secret');
        $invitation->update(['status' => InvitationStatus::Active]);
        [$cookie, $secret] = $this->claim($invitation);
        $this->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertOk()->assertJsonPath('data.privateInvitation.trustState', 'claimed_elsewhere')
            ->assertJsonPath('data.privateInvitation.rsvp', null)
            ->assertDontSee('Ana Secret');

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
            ->postJson('/api/private-invitations/site', ['token' => $old->encrypted_token])
            ->assertOk()
            ->assertJsonPath('data.privateInvitation.linkStatus', 'historical')
            ->assertJsonPath('data.privateInvitation.trustState', 'trusted')
            ->assertJsonPath('data.privateInvitation.currentPath', '/i/'.$newToken)
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Ana Secret');
        $this->withUnencryptedCookie($cookie, 'invalid-browser-secret')
            ->postJson('/api/private-invitations/site', ['token' => $old->encrypted_token])
            ->assertNotFound();
        $this->postJson('/api/private-invitations/site', ['token' => PrivateInvitationToken::generate()])->assertNotFound();
    }

    public function test_unpublished_and_published_switching_use_current_event_presentation_without_changing_trust(): void
    {
        $event = Event::factory()->create();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana Secret']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $this->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertOk()->assertJsonPath('data.status', 'unpublished')->assertJsonPath('data.website', null)
            ->assertJsonPath('data.privateInvitation.rsvp', null)->assertDontSee('Ana Secret');

        $first = $this->initializeWebsite($event);
        $second = Website::factory()->for($event)->create([
            'name' => 'Second',
            'template_key' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ]);
        app(PublishWebsite::class)->handle($event, $first);
        [$cookie, $secret] = $this->claim($invitation);
        $this->withUnencryptedCookie($cookie, $secret)
            ->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.website.id', $first->id);
        app(PublishWebsite::class)->handle($event->refresh(), $second);
        $this->postJson('/api/private-invitations/site', ['token' => $token])
            ->assertJsonPath('data.website.id', $second->id)
            ->assertJsonPath('data.privateInvitation.rsvp.guests.0.name', 'Ana Secret');
        $this->assertDatabaseCount('invitation_browser_credentials', 1);
    }

    /** @return array{Event, Invitation} */
    private function publishedInvitation(bool $twoGuests = false): array
    {
        $event = Event::factory()->create(['rsvp_is_open' => false]);
        $website = $this->initializeWebsite($event);
        app(PublishWebsite::class)->handle($event, $website);
        $guests = [['first_name' => 'Ana Secret']];
        if ($twoGuests) {
            $guests[] = ['first_name' => 'Pedro Hidden'];
        }

        return [$event, app(CreateInvitation::class)->handle($event, $guests)];
    }

    /** @return array{string, string} */
    private function claim($invitation): array
    {
        $response = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $name = app(InvitationTrustCookie::class)->name($credential);

        return [$name, $response->getCookie($name, false)->getValue()];
    }
}
