<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\DeleteInvitation;
use App\Actions\Invitations\ResolvePrivateInvitationLink;
use App\Actions\Websites\PublishWebsite;
use App\Enums\EventMembershipRole;
use App\Enums\PrivateInvitationLinkStatus;
use App\Invitations\PrivateInvitationToken;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\InvitationBrowserCredential;
use App\Models\InvitationPrivateLink;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivateInvitationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_creation_path_provisions_one_secure_current_private_link(): void
    {
        $event = Event::factory()->create();
        $first = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $second = Invitation::factory()->for($event)->create();

        foreach ([$first, $second] as $invitation) {
            $links = $invitation->privateLinks()->get();
            $this->assertCount(1, $links);
            $this->assertSame('current', $links->sole()->current_slot);
            $token = $links->sole()->encrypted_token;
            $this->assertSame(43, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
            $this->assertStringNotContainsString($invitation->id, $token);
            $this->assertStringNotContainsString($event->id, $token);
            if ($guestId = $invitation->guests()->value('id')) {
                $this->assertStringNotContainsString($guestId, $token);
            }
            if ($invitation->custom_name !== null) {
                $this->assertStringNotContainsString($invitation->custom_name, $token);
            }
        }

        $this->assertNotSame($first->currentPrivateLink->encrypted_token, $second->currentPrivateLink->encrypted_token);
        $rawRow = DB::table('invitation_private_links')->where('id', $first->currentPrivateLink->id)->first();
        $this->assertNotSame($first->currentPrivateLink->encrypted_token, $rawRow->encrypted_token);
        $this->assertStringNotContainsString($first->currentPrivateLink->encrypted_token, $rawRow->encrypted_token);
        $this->assertSame(PrivateInvitationToken::hash($first->currentPrivateLink->encrypted_token), $rawRow->token_hash);
    }

    public function test_hash_and_current_link_constraints_are_database_enforced(): void
    {
        $invitation = Invitation::factory()->create();
        $link = $invitation->currentPrivateLink;

        $this->expectException(QueryException::class);
        InvitationPrivateLink::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'token_hash' => $link->token_hash,
            'encrypted_token' => PrivateInvitationToken::generate(),
            'current_slot' => null,
        ]);
    }

    public function test_private_link_current_slot_rejects_every_noncanonical_sentinel(): void
    {
        $invitation = Invitation::factory()->create();

        $this->expectException(QueryException::class);
        InvitationPrivateLink::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'token_hash' => PrivateInvitationToken::hash(PrivateInvitationToken::generate()),
            'encrypted_token' => PrivateInvitationToken::generate(),
            'current_slot' => 'alternate',
        ]);
    }

    public function test_management_detail_exposes_only_the_private_path_to_authorized_users(): void
    {
        [$event, $owner] = $this->eventOwner();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $path = '/i/'.$invitation->currentPrivateLink->encrypted_token;

        $response = $this->actingAs($owner)
            ->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")
            ->assertOk()
            ->assertJsonPath('data.privateInvitation.path', $path)
            ->assertJsonMissingPath('data.privateInvitation.tokenHash')
            ->assertJsonMissingPath('data.privateInvitation.encryptedToken');

        $this->assertStringNotContainsString($invitation->currentPrivateLink->token_hash, $response->getContent());
        $this->actingAs(User::factory()->create())
            ->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")->assertForbidden();
    }

    public function test_resolver_distinguishes_current_historical_and_unknown_without_scanning_ciphertext(): void
    {
        $invitation = Invitation::factory()->create();
        $current = $invitation->currentPrivateLink;
        $historicalToken = PrivateInvitationToken::generate();
        InvitationPrivateLink::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'token_hash' => PrivateInvitationToken::hash($historicalToken),
            'encrypted_token' => $historicalToken,
            'current_slot' => null,
            'retired_at' => now(),
        ]);

        $resolver = app(ResolvePrivateInvitationLink::class);
        $resolved = $resolver->handle($current->encrypted_token);
        $this->assertSame(PrivateInvitationLinkStatus::Current, $resolved->status);
        $this->assertTrue($resolved->link->invitation->is($invitation));
        $this->assertTrue($resolved->link->invitation->event->is($invitation->event));
        $this->assertSame(PrivateInvitationLinkStatus::Historical, $resolver->handle($historicalToken)->status);
        $this->assertSame(PrivateInvitationLinkStatus::Unknown, $resolver->handle(PrivateInvitationToken::generate())->status);
    }

    public function test_link_identity_survives_publication_lifecycle_guest_and_rsvp_independent_changes(): void
    {
        $event = Event::factory()->create();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana'], ['first_name' => 'Pedro']]);
        $linkId = $invitation->currentPrivateLink->id;
        $token = $invitation->currentPrivateLink->encrypted_token;

        $firstWebsite = Website::factory()->for($event)->create();
        $secondWebsite = Website::factory()->for($event)->create(['name' => 'Second']);
        app(PublishWebsite::class)->handle($event, $firstWebsite);
        app(PublishWebsite::class)->handle($event->refresh(), $secondWebsite);
        $invitation->update(['status' => 'inactive']);
        $invitation->update(['status' => 'active']);
        $invitation->guests()->first()->update(['first_name' => 'Ana Maria']);
        $invitation->guests()->create(['event_id' => $event->id, 'first_name' => 'Maria']);
        $invitation->guests()->where('first_name', 'Pedro')->update(['status' => 'inactive']);
        $invitation->guests()->where('first_name', 'Pedro')->update(['status' => 'active']);

        $this->assertSame($linkId, $invitation->fresh()->currentPrivateLink->id);
        $this->assertSame($token, $invitation->fresh()->currentPrivateLink->encrypted_token);
    }

    public function test_automatic_link_does_not_block_hard_delete_and_cascades(): void
    {
        $invitation = Invitation::factory()->create();
        $linkId = $invitation->currentPrivateLink->id;

        app(DeleteInvitation::class)->handle($invitation);

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
        $this->assertDatabaseMissing('invitation_private_links', ['id' => $linkId]);
    }

    public function test_trust_foundation_is_separate_invitation_scoped_hash_only_and_issues_nothing(): void
    {
        $invitation = Invitation::factory()->create();
        $this->assertDatabaseCount('invitation_browser_credentials', 0);
        $this->assertFalse(in_array('secret', (new InvitationBrowserCredential)->getFillable(), true));
        $this->assertSame($invitation->id, $invitation->currentPrivateLink->invitation_id);

        $rawSecret = PrivateInvitationToken::generate();
        $credential = InvitationBrowserCredential::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'secret_hash' => PrivateInvitationToken::hash($rawSecret),
            'current_slot' => 'current',
            'browser_family' => 'Test Browser',
            'platform' => 'Test Platform',
        ]);

        $this->assertArrayNotHasKey('secret_hash', $credential->toArray());
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('invitation_browser_credentials', 'secret'));
        $this->assertSame(PrivateInvitationToken::hash($rawSecret), DB::table('invitation_browser_credentials')->value('secret_hash'));
        $this->assertNotSame($credential->id, $invitation->currentPrivateLink->id);
    }

    public function test_only_one_current_browser_credential_is_structurally_allowed(): void
    {
        $invitation = Invitation::factory()->create();
        InvitationBrowserCredential::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'secret_hash' => PrivateInvitationToken::hash(PrivateInvitationToken::generate()),
            'current_slot' => 'current',
        ]);

        $this->expectException(QueryException::class);
        InvitationBrowserCredential::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'secret_hash' => PrivateInvitationToken::hash(PrivateInvitationToken::generate()),
            'current_slot' => 'current',
        ]);
    }

    public function test_browser_credential_current_slot_rejects_every_noncanonical_sentinel(): void
    {
        $invitation = Invitation::factory()->create();

        $this->expectException(QueryException::class);
        InvitationBrowserCredential::query()->forceCreate([
            'invitation_id' => $invitation->id,
            'secret_hash' => PrivateInvitationToken::hash(PrivateInvitationToken::generate()),
            'current_slot' => 'alternate',
        ]);
    }

    public function test_public_event_site_remains_private_data_free(): void
    {
        $event = Event::factory()->create(['slug' => 'private-free']);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Secret Guest']]);
        $payload = $this->getJson('/api/public/events/private-free/site')->assertOk()->getContent();

        foreach ([$invitation->id, $invitation->currentPrivateLink->id, $invitation->currentPrivateLink->encrypted_token,
            $invitation->currentPrivateLink->token_hash, 'Secret Guest', 'privateInvitation', 'browserCredentials'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $payload);
        }
    }

    public function test_migration_backfills_existing_invitations_without_changing_domain_state(): void
    {
        $event = Event::factory()->create();
        $migration = require database_path('migrations/2026_09_23_200000_create_invitation_access_foundation.php');
        $migration->down();
        $invitationId = (string) Str::ulid();
        $createdAt = now()->subDay()->startOfSecond();
        DB::table('invitations')->insert([
            'id' => $invitationId,
            'event_id' => $event->id,
            'custom_name' => 'Existing Household',
            'status' => 'inactive',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $migration->up();

        $this->assertDatabaseCount('invitation_private_links', 1);
        $this->assertDatabaseHas('invitation_private_links', ['invitation_id' => $invitationId, 'current_slot' => 'current']);
        $this->assertDatabaseHas('invitations', [
            'id' => $invitationId,
            'custom_name' => 'Existing Household',
            'status' => 'inactive',
        ]);
        $this->assertDatabaseCount('invitation_browser_credentials', 0);
    }

    /** @return array{Event, User} */
    private function eventOwner(): array
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);

        return [$event, $owner];
    }
}
