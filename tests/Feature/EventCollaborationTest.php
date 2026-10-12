<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\PublishWebsite;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Event $event;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create(['email' => 'manager@example.com']);
        $this->event = app(CreateEvent::class)->handle($this->owner, ['name' => 'Wedding']);
        $this->url = "/api/events/{$this->event->id}/collaborators";
    }

    private function grant(): EventMembership
    {
        return $this->event->memberships()->create(['user_id' => $this->manager->id, 'role' => EventMembershipRole::Admin]);
    }

    public function test_owner_adds_normalized_email_and_manager_has_existing_operational_access(): void
    {
        $this->actingAs($this->owner)->postJson($this->url, ['email' => ' MANAGER@EXAMPLE.COM '])
            ->assertCreated()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.email', $this->manager->email);
        $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role']]]);
        $this->actingAs($this->manager)->getJson('/api/events')->assertOk()->assertJsonPath('data.0.id', $this->event->id)->assertJsonPath('data.0.membershipRole', 'admin');
        $this->getJson("/api/events/{$this->event->id}")->assertOk();
        $this->putJson("/api/events/{$this->event->id}/rsvp-settings", ['isOpen' => true, 'deadline' => null])->assertOk();
        $this->getJson("/api/events/{$this->event->id}/invitations")->assertOk();
        $this->getJson("/api/events/{$this->event->id}/media")->assertOk();
    }

    public function test_manager_nonmember_and_nonowner_superadmin_cannot_manage_collaborators(): void
    {
        $membership = $this->grant();
        foreach ([$this->manager, User::factory()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->getJson($this->url)->assertForbidden();
            $this->postJson($this->url, ['email' => $actor->email, 'role' => 'owner'])->assertForbidden();
            $this->deleteJson("{$this->url}/{$membership->id}")->assertForbidden();
        }
        $this->assertDatabaseCount('event_memberships', 2);
    }

    public function test_duplicates_self_unknown_and_injected_fields_are_rejected(): void
    {
        $this->grant();
        $this->actingAs($this->owner);
        foreach ([$this->manager->email, $this->owner->email, 'unknown@example.com'] as $email) {
            $this->postJson($this->url, ['email' => $email])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        foreach (['user_id', 'userId', 'role', 'platform_role', 'owner_user_id', 'event_id'] as $field) {
            $this->postJson($this->url, ['email' => $this->manager->email, $field => 'owner'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->url, ['email' => ['bad']])->assertUnprocessable();
        $this->postJson($this->url, ['email' => 'not-an-email'])->assertUnprocessable();
        $this->assertDatabaseCount('event_memberships', 2);
    }

    public function test_owner_cannot_be_removed_and_foreign_membership_is_not_found(): void
    {
        $foreign = app(CreateEvent::class)->handle($this->owner, ['name' => 'Other']);
        $membership = $foreign->memberships()->create(['user_id' => $this->manager->id, 'role' => EventMembershipRole::Admin]);
        $ownerMembership = $this->event->memberships()->sole();
        $this->actingAs($this->owner)->deleteJson("{$this->url}/{$ownerMembership->id}")->assertUnprocessable();
        $this->deleteJson("{$this->url}/{$membership->id}")->assertNotFound();
        $this->assertDatabaseHas('event_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('event_memberships', ['id' => $ownerMembership->id, 'role' => 'owner']);
    }

    public function test_zero_and_multiple_owners_fail_closed_for_all_collaborator_operations(): void
    {
        $member = $this->grant();
        $this->event->memberships()->where('role', 'owner')->delete();
        $this->assertManagementDenied($member);
        $this->event->memberships()->create(['user_id' => $this->owner->id, 'role' => EventMembershipRole::Owner]);
        $this->event->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => EventMembershipRole::Owner]);
        $this->assertManagementDenied($member);
    }

    private function assertManagementDenied(EventMembership $member): void
    {
        $this->actingAs($this->owner)->getJson($this->url)->assertForbidden();
        $this->postJson($this->url, ['email' => $this->manager->email])->assertForbidden();
        $this->deleteJson("{$this->url}/{$member->id}")->assertForbidden();
    }

    public function test_removal_revokes_next_request_without_affecting_other_events_or_login(): void
    {
        $membership = $this->grant();
        $other = app(CreateEvent::class)->handle($this->manager, ['name' => 'Other']);
        $this->actingAs($this->owner)->deleteJson("{$this->url}/{$membership->id}")->assertNoContent();
        $this->actingAs($this->manager)->getJson("/api/events/{$this->event->id}")->assertForbidden();
        $this->putJson("/api/events/{$this->event->id}/rsvp-settings", ['isOpen' => true, 'deadline' => null])->assertForbidden();
        $this->getJson("/api/events/{$this->event->id}/invitations")->assertForbidden();
        $this->getJson("/api/events/{$this->event->id}/media")->assertForbidden();
        $this->getJson("/api/events/{$other->id}")->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
        $this->getJson('/api/events')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
        $this->postJson($this->url, ['email' => $this->manager->email])->assertUnauthorized();
        $this->deleteJson("{$this->url}/anything")->assertUnauthorized();
    }

    public function test_repeat_add_has_one_grant_and_database_uniqueness_is_the_final_guard(): void
    {
        $this->actingAs($this->owner)->postJson($this->url, ['email' => $this->manager->email])->assertCreated();
        $this->postJson($this->url, ['email' => $this->manager->email])->assertUnprocessable();
        $this->assertSame(1, $this->event->memberships()->where('user_id', $this->manager->id)->count());
        $this->expectException(QueryException::class);
        $this->grant();
    }

    public function test_collaboration_does_not_change_draft_publication_or_public_guest_payload(): void
    {
        $website = app(CreateWebsiteProject::class)->handle($this->event, 'Main', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        app(PublishWebsite::class)->handle($this->event, $website);
        $before = $website->fresh()->getAttributes();
        $sections = $website->sections()->get()->map->getAttributes()->all();
        $publicUrl = "/api/public/events/{$this->event->slug}/site";
        $public = $this->getJson($publicUrl)->assertOk()->json();
        $membershipId = $this->actingAs($this->owner)->postJson($this->url, ['email' => $this->manager->email])->assertCreated()->json('data.id');
        $this->assertSame($public, $this->getJson($publicUrl)->assertOk()->json());
        $this->deleteJson("{$this->url}/{$membershipId}")->assertNoContent();
        $this->assertSame($before, $website->fresh()->getAttributes());
        $this->assertSame($sections, $website->sections()->get()->map->getAttributes()->all());
        $this->assertSame($public, $this->getJson($publicUrl)->assertOk()->json());
    }
}
