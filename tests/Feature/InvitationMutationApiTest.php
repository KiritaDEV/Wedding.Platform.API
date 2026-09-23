<?php

namespace Tests\Feature;

use App\Actions\Invitations\AssignWeddingRole;
use App\Actions\Invitations\CreateCustomWeddingRole;
use App\Actions\Invitations\CreateInvitation;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationStatus;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WeddingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationMutationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept', 'application/json');
    }

    public function test_owner_creates_and_shows_atomic_invitation_with_existing_and_shared_inline_roles(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $bridesmaid = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        $custom = app(CreateCustomWeddingRole::class)->handle($event, 'Usher');

        $response = $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations", [
            'customName' => ' Ceremony Party ',
            'customRoles' => [['clientKey' => 'reader', 'name' => 'Reader']],
            'guests' => [
                ['firstName' => 'Ana', 'lastName' => 'Cruz', 'relationship' => 'friend', 'side' => 'bride', 'status' => 'active', 'weddingRoleIds' => [$bridesmaid->id], 'customWeddingRoleKeys' => ['reader']],
                ['firstName' => 'Pedro', 'status' => 'active', 'weddingRoleIds' => [$custom->id], 'customWeddingRoleKeys' => ['reader']],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.customName', 'Ceremony Party')
            ->assertJsonPath('data.displayName', 'Ceremony Party')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(2, 'data.guests');

        $invitation = Invitation::query()->sole();
        $this->assertCount(2, $invitation->guests);
        $this->assertDatabaseCount('wedding_roles', 15);
        $this->assertSame(2, WeddingRole::query()->where('name', 'Reader')->sole()->guests()->count());

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")
            ->assertOk()->assertJsonPath('data.guests.0.relationship', 'friend')
            ->assertJsonMissingPath('data.guests.0.normalized_first_name')
            ->assertJsonMissingPath('data.guests.0.weddingRoles.0.scope_key');
        $this->assertSame($response->json('data.id'), $invitation->id);
    }

    public function test_create_validates_duplicate_names_cross_event_roles_and_rolls_back_draft_roles(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $foreign = Event::factory()->create();
        $foreignRole = app(CreateCustomWeddingRole::class)->handle($foreign, 'Foreign');

        $payload = [
            'customName' => 'Rejected',
            'customRoles' => [['clientKey' => 'reader', 'name' => 'Reader']],
            'guests' => [
                ['firstName' => ' José ', 'weddingRoleIds' => [$foreignRole->id], 'customWeddingRoleKeys' => ['reader']],
                ['firstName' => "Jose\u{0301}"],
            ],
        ];
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('guests');
        $this->assertDatabaseMissing('invitations', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('wedding_roles', ['event_id' => $event->id, 'name' => 'Reader']);

        $payload['guests'] = [['firstName' => 'Unique', 'weddingRoleIds' => [$foreignRole->id], 'customWeddingRoleKeys' => ['reader']]];
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('guests');
        $this->assertDatabaseMissing('wedding_roles', ['event_id' => $event->id, 'name' => 'Reader']);
    }

    public function test_atomic_update_changes_complete_state_and_resolves_stale_draft_role(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Admin);
        $invitation = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana'], ['first_name' => 'Remove Me'],
        ], 'Old');
        $ana = $invitation->guests->firstWhere('first_name', 'Ana');
        $removed = $invitation->guests->firstWhere('first_name', 'Remove Me');
        $oldRole = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        app(AssignWeddingRole::class)->handle($ana, $oldRole);
        $reader = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$invitation->id}", [
            'customName' => 'Updated',
            'customRoles' => [
                ['clientKey' => 'reader-stale', 'name' => ' reader '],
                ['clientKey' => 'lector', 'name' => 'Lector'],
            ],
            'deletedGuestIds' => [$removed->id],
            'guests' => [
                ['id' => $ana->id, 'firstName' => 'Ana Maria', 'relationship' => 'family_member', 'side' => 'both', 'customWeddingRoleKeys' => ['reader-stale', 'lector']],
                ['firstName' => 'Pedro', 'relationship' => 'colleague', 'side' => 'groom', 'customWeddingRoleKeys' => ['lector']],
            ],
        ])->assertOk()->assertJsonPath('data.customName', 'Updated')->assertJsonCount(2, 'data.guests');

        $invitation->refresh();
        $this->assertSame(['Ana Maria', 'Pedro'], $invitation->guests()->orderBy('created_at')->pluck('first_name')->all());
        $this->assertDatabaseMissing('guests', ['first_name' => 'Remove Me']);
        $this->assertSame(1, WeddingRole::query()->where('normalized_name', 'reader')->where('event_id', $event->id)->count());
        $this->assertSame($reader->id, $ana->fresh()->weddingRoles()->where('name', 'Reader')->value('wedding_roles.id'));
        $this->assertDatabaseCount('wedding_roles', 15);
    }

    public function test_failed_update_rolls_back_invitation_guests_roles_and_draft_role(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $target = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']], 'Original');
        $ana = $target->guests->first();
        app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria']]);
        $role = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        app(AssignWeddingRole::class)->handle($ana, $role);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$target->id}", [
            'customName' => 'Changed',
            'customRoles' => [['clientKey' => 'reader', 'name' => 'Reader']],
            'guests' => [
                ['id' => $ana->id, 'firstName' => 'Ana Changed', 'customWeddingRoleKeys' => ['reader']],
                ['firstName' => 'New Guest'],
                ['firstName' => ' maria '],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('guests');

        $this->assertSame('Original', $target->fresh()->custom_name);
        $this->assertSame('Ana', $ana->fresh()->first_name);
        $this->assertTrue($ana->fresh()->weddingRoles->contains($role));
        $this->assertDatabaseMissing('guests', ['first_name' => 'New Guest']);
        $this->assertDatabaseMissing('wedding_roles', ['event_id' => $event->id, 'name' => 'Reader']);
    }

    public function test_update_rejects_zero_guests_and_foreign_guest_ids(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $first = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $second = app(CreateInvitation::class)->handle($event, [['first_name' => 'Pedro']]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$first->id}", ['guests' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('guests');
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$first->id}", [
            'guests' => [['id' => $second->guests->first()->id, 'firstName' => 'Injected']],
        ])->assertUnprocessable()->assertJsonValidationErrors('guests');
        $this->assertSame('Ana', $first->guests()->sole()->first_name);
    }

    public function test_lifecycle_is_idempotent_and_preserves_guest_state(): void
    {
        [$event, $admin] = $this->eventMember(EventMembershipRole::Admin);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $guestId = $invitation->guests->first()->id;

        foreach (['deactivate', 'deactivate'] as $operation) {
            $this->actingAs($admin)->postJson("/api/events/{$event->id}/invitations/{$invitation->id}/{$operation}")
                ->assertOk()->assertJsonPath('data.status', 'inactive');
        }
        foreach (['activate', 'activate'] as $operation) {
            $this->actingAs($admin)->postJson("/api/events/{$event->id}/invitations/{$invitation->id}/{$operation}")
                ->assertOk()->assertJsonPath('data.status', 'active');
        }
        $this->assertDatabaseHas('guests', ['id' => $guestId]);
    }

    public function test_move_preserves_identity_and_roles_allows_inactive_destination_and_protects_last_guest(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $source = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana'], ['first_name' => 'Pedro']]);
        $destination = app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria']]);
        $destination->update(['status' => InvitationStatus::Inactive]);
        $guest = $source->guests->firstWhere('first_name', 'Ana');
        $role = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        app(AssignWeddingRole::class)->handle($guest, $role);

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations/{$source->id}/guests/{$guest->id}/move", [
            'destinationInvitationId' => $destination->id,
        ])->assertOk()->assertJsonPath('data.id', $guest->id);
        $this->assertSame($destination->id, $guest->fresh()->invitation_id);
        $this->assertTrue($guest->fresh()->weddingRoles->contains($role));
        $this->assertSame('Pedro', $source->fresh()->effectiveName());
        $this->assertSame('Ana & Maria', $destination->fresh()->effectiveName());

        $pedro = $source->guests()->sole();
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations/{$source->id}/guests/{$pedro->id}/move", [
            'destinationInvitationId' => $destination->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('guest');
    }

    public function test_delete_is_hard_scoped_and_preserves_custom_roles_and_unrelated_data(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $target = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $other = app(CreateInvitation::class)->handle($event, [['first_name' => 'Pedro']]);
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        app(AssignWeddingRole::class)->handle($target->guests->first(), $role);

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/invitations/{$target->id}")->assertNoContent();
        $this->assertDatabaseMissing('invitations', ['id' => $target->id]);
        $this->assertDatabaseHas('invitations', ['id' => $other->id]);
        $this->assertDatabaseHas('wedding_roles', ['id' => $role->id]);
    }

    public function test_all_routes_enforce_authentication_membership_event_scope_and_super_admin_bypass(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $foreignEvent = Event::factory()->create();
        $foreignInvitation = app(CreateInvitation::class)->handle($foreignEvent, [['first_name' => 'Foreign']]);
        $outsider = User::factory()->create();
        $super = User::factory()->superAdmin()->create();

        $this->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")->assertUnauthorized();
        $this->actingAs($outsider)->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations/{$foreignInvitation->id}")->assertNotFound();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$foreignInvitation->id}", ['guests' => [['firstName' => 'X']]])->assertNotFound();
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations/{$foreignInvitation->id}/deactivate")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/invitations/{$foreignInvitation->id}")->assertNotFound();
        $this->actingAs($super)->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")->assertOk();
    }

    /** @return array{Event, User} */
    private function eventMember(EventMembershipRole $role): array
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        EventMembership::factory()->for($event)->for($user)->create(['role' => $role]);

        return [$event, $user];
    }
}
