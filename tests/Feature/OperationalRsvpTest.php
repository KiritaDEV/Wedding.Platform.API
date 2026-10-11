<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\MoveGuest;
use App\Actions\Invitations\UpdateInvitation;
use App\Enums\EventMembershipRole;
use App\Enums\GuestStatus;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalRsvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_update_persists_current_state_and_full_immutable_snapshot(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";

        $this->actingAs($owner)->putJson($url, ['responses' => [
            ['guestId' => $neil->id, 'response' => 'attending'],
            ['guestId' => $hazel->id, 'response' => null],
        ], 'note' => ' Guest informed us via Messenger '])->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.rsvp.status', 'partial')
            ->assertJsonPath('data.rsvp.attendingCount', 1)
            ->assertJsonPath('data.rsvp.pendingCount', 1)
            ->assertJsonPath('data.submission.actorType', 'management_user')
            ->assertJsonPath('data.submission.actorName', $owner->name)
            ->assertJsonPath('data.submission.note', 'Guest informed us via Messenger')
            ->assertJsonCount(2, 'data.submission.items');

        $this->assertSame('attending', $neil->refresh()->rsvp_response->value);
        $this->assertNull($hazel->refresh()->rsvp_response);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertDatabaseCount('rsvp_submission_items', 2);
    }

    public function test_no_op_creates_nothing_and_exact_active_set_is_authoritative(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";
        $payload = ['responses' => [['guestId' => $neil->id, 'response' => null], ['guestId' => $hazel->id, 'response' => null]], 'note' => 'Ignored'];

        $this->actingAs($owner)->putJson($url, $payload)->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.lastResponse', null);
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->actingAs($owner)->putJson($url, ['responses' => [['guestId' => $neil->id, 'response' => 'attending']]])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['responses' => [['guestId' => $neil->id, 'response' => 'maybe'], ['guestId' => $hazel->id, 'response' => null]]])->assertUnprocessable();
    }

    public function test_history_preserves_name_and_invitation_when_guest_is_renamed_and_moved(): void
    {
        [$event, $owner] = $this->eventMember();
        $source = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        $destination = app(CreateInvitation::class)->handle($event, [['first_name' => 'Jessa']]);
        [$neil, $hazel] = $source->guests;
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$source->id}/rsvp", ['responses' => [
            ['guestId' => $neil->id, 'response' => 'attending'], ['guestId' => $hazel->id, 'response' => null],
        ]])->assertOk();
        $neil->update(['first_name' => 'Renamed']);
        app(MoveGuest::class)->handle($source, $neil, $destination);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations/{$source->id}/rsvp-history")
            ->assertOk()->assertJsonPath('data.0.items.0.guestName', 'Neil');
        $this->assertSame('attending', $neil->refresh()->rsvp_response->value);
        $this->assertDatabaseHas('rsvp_submissions', ['invitation_id' => $source->id]);
        $this->assertDatabaseMissing('rsvp_submissions', ['invitation_id' => $destination->id]);
    }

    public function test_lifecycle_excludes_inactive_guest_without_changing_response_or_history(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$invitation->id}/rsvp", ['responses' => [
            ['guestId' => $neil->id, 'response' => 'attending'], ['guestId' => $hazel->id, 'response' => null],
        ]])->assertOk();
        $hazel->update(['status' => GuestStatus::Inactive]);

        $row = $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->assertOk()->json('data.0');
        $this->assertSame('complete', $row['rsvp']['status']);
        $this->assertSame(1, $row['rsvp']['attendingCount']);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertNull($hazel->refresh()->rsvp_response);
    }

    public function test_participation_blocks_guest_and_invitation_hard_delete_but_authorization_is_preserved(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";
        $payload = ['responses' => [['guestId' => $neil->id, 'response' => 'declined'], ['guestId' => $hazel->id, 'response' => null]]];

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson($url, $payload)->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/invitations/{$invitation->id}")->assertUnprocessable();
        $this->expectException(ValidationException::class);
        app(UpdateInvitation::class)->handle($invitation, [['id' => $hazel->id, 'first_name' => 'Hazel']], null, [], [$neil->id]);
    }

    public function test_inactive_invitation_freezes_material_and_no_op_updates_until_reactivated(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";
        $this->actingAs($owner)->putJson($url, ['responses' => [
            ['guestId' => $neil->id, 'response' => 'attending'], ['guestId' => $hazel->id, 'response' => null],
        ]])->assertOk();
        $lastResponse = $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->json('data.0.lastResponse');
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations/{$invitation->id}/deactivate")->assertOk();

        foreach ([
            [['guestId' => $neil->id, 'response' => 'declined'], ['guestId' => $hazel->id, 'response' => 'attending']],
            [['guestId' => $neil->id, 'response' => 'attending'], ['guestId' => $hazel->id, 'response' => null]],
        ] as $responses) {
            $this->actingAs($owner)->putJson($url, ['responses' => $responses])->assertUnprocessable()
                ->assertJsonPath('errors.invitation.0', 'Reactivate this Invitation before changing RSVP responses.');
        }

        $this->assertSame('attending', $neil->refresh()->rsvp_response->value);
        $this->assertNull($hazel->refresh()->rsvp_response);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertDatabaseCount('rsvp_submission_items', 2);
        $this->assertSame($lastResponse, $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->json('data.0.lastResponse'));

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations/{$invitation->id}/activate")->assertOk();
        $this->assertSame('attending', $neil->refresh()->rsvp_response->value);
        $this->assertDatabaseCount('rsvp_submissions', 1);
        $this->assertSame($lastResponse, $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->json('data.0.lastResponse'));

        $this->actingAs($owner)->putJson($url, ['responses' => [
            ['guestId' => $neil->id, 'response' => 'declined'], ['guestId' => $hazel->id, 'response' => 'attending'],
        ]])->assertOk()->assertJsonPath('data.changed', true);
        $this->assertDatabaseCount('rsvp_submissions', 2);
        $this->assertDatabaseCount('rsvp_submission_items', 4);
    }

    public function test_management_availability_depends_on_active_invitation_not_event_rsvp_window(): void
    {
        foreach ([
            ['rsvp_is_open' => true, 'rsvp_deadline' => now()->addDay()],
            ['rsvp_is_open' => false, 'rsvp_deadline' => now()->addDay()],
            ['rsvp_is_open' => true, 'rsvp_deadline' => now()->subDay()],
        ] as $eventState) {
            $event = Event::factory()->create($eventState);
            [, $owner] = $this->eventMember(EventMembershipRole::Owner, $event);
            $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);

            $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$invitation->id}/rsvp", ['responses' => [[
                'guestId' => $invitation->guests->sole()->id, 'response' => 'attending',
            ]]])->assertOk()->assertJsonPath('data.changed', true);
        }
    }

    public function test_admin_and_super_admin_can_manage_while_management_changes_create_no_notification_or_access_audit(): void
    {
        $event = Event::factory()->create();
        [, $admin] = $this->eventMember(EventMembershipRole::Admin, $event);
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['platform_role' => PlatformRole::SuperAdmin])->save();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
        $guest = $invitation->guests->sole();
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";

        $this->actingAs($admin)->putJson($url, ['responses' => [['guestId' => $guest->id, 'response' => 'attending']]])
            ->assertOk()->assertJsonPath('data.rsvp.status', 'complete');
        $this->actingAs($superAdmin)->putJson($url, ['responses' => [['guestId' => $guest->id, 'response' => null]]])
            ->assertOk()->assertJsonPath('data.rsvp.status', 'pending');

        $this->assertNull($guest->refresh()->rsvp_response);
        $this->assertDatabaseCount('rsvp_submissions', 2);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertDatabaseCount('invitation_access_audits', 0);
    }

    public function test_history_uses_stable_cursor_pagination_and_hides_internal_actor_id(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
        $guest = $invitation->guests->sole();
        $mutation = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp";

        foreach (range(1, 26) as $index) {
            $response = $index % 2 === 0 ? 'attending' : 'declined';
            $this->actingAs($owner)->putJson($mutation, ['responses' => [['guestId' => $guest->id, 'response' => $response]]])->assertOk();
        }

        $history = "/api/events/{$event->id}/invitations/{$invitation->id}/rsvp-history";
        $first = $this->actingAs($owner)->getJson($history)->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonMissingPath('data.0.actorUserId')->json();
        $this->assertSame('attending', $first['data'][0]['items'][0]['response']);
        $this->assertNotNull($first['meta']['nextCursor']);
        $second = $this->actingAs($owner)->getJson($history.'?cursor='.urlencode($first['meta']['nextCursor']))
            ->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }

    public function test_real_guest_filters_and_event_delete_removes_the_complete_rsvp_aggregate(): void
    {
        [$event, $owner] = $this->eventMember();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil'], ['first_name' => 'Hazel']]);
        [$neil, $hazel] = $invitation->guests;
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/invitations/{$invitation->id}/rsvp", ['responses' => [
            ['guestId' => $neil->id, 'response' => 'attending'], ['guestId' => $hazel->id, 'response' => 'declined'],
        ]])->assertOk();

        foreach (['attending', 'declined'] as $response) {
            $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations?guestResponses[]={$response}")
                ->assertOk()->assertJsonPath('meta.pagination.total', 1);
        }
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations?guestResponses[]=pending")
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);

        $event->delete();
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertDatabaseCount('rsvp_submission_items', 0);
        $this->assertDatabaseMissing('guests', ['id' => $neil->id]);
    }

    private function eventMember(EventMembershipRole $role = EventMembershipRole::Owner, ?Event $event = null): array
    {
        $event ??= Event::factory()->create(['rsvp_is_open' => false, 'rsvp_deadline' => now()->subDay()]);
        $user = User::factory()->create();
        EventMembership::factory()->for($event)->for($user)->create(['role' => $role]);

        return [$event, $user];
    }
}
