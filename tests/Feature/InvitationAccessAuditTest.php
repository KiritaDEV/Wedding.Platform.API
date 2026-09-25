<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\RecordInvitationAccessAudit;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationAccessAuditActor;
use App\Enums\InvitationAccessAuditEvent;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\InvitationAccessAudit;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class InvitationAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_claim_is_a_single_snapshot_event_and_reads_create_no_noise(): void
    {
        [, , $invitation] = $this->managedInvitation();
        $token = $invitation->currentPrivateLink->encrypted_token;

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0')->postJson('/api/private-invitations/open', ['token' => $token])->assertOk();
        $this->postJson('/api/private-invitations/context', ['token' => $token])->assertOk();

        $audit = InvitationAccessAudit::query()->sole();
        $this->assertSame(InvitationAccessAuditEvent::TrustedAccessClaimed, $audit->event_type);
        $this->assertSame(InvitationAccessAuditActor::PrivateBrowser, $audit->actor_type);
        $this->assertSame(['browserFamily' => 'Chrome', 'platform' => 'Windows'], $audit->metadata);
        $this->assertNull($audit->actor_user_id);
    }

    public function test_management_read_is_authorized_cursor_paginated_ordered_and_safe(): void
    {
        [$event, $owner, $invitation] = $this->managedInvitation();
        foreach (range(1, 27) as $index) {
            app(RecordInvitationAccessAudit::class)->handle($invitation, InvitationAccessAuditEvent::PrivateLinkRotated, InvitationAccessAuditActor::ManagementUser, ['secret' => 'never-return'], $owner);
            $this->travel(1)->second();
        }
        $snapshotName = $owner->name;
        $owner->update(['name' => 'Renamed Later']);
        $url = "/api/events/{$event->id}/invitations/{$invitation->id}/access-audit";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $first = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(25, 'data');
        $this->assertNotNull($first->json('meta.nextCursor'));
        $first->assertJsonPath('data.0.actor.name', $snapshotName)
            ->assertJsonMissingPath('data.0.actorUserId')->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.details.secret');
        $second = $this->actingAs($owner)->getJson($url.'?cursor='.urlencode($first->json('meta.nextCursor')))->assertOk()->assertJsonCount(2, 'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_audit_entries_are_immutable_but_cascade_with_legitimate_invitation_delete(): void
    {
        [, , $invitation] = $this->managedInvitation();
        $audit = app(RecordInvitationAccessAudit::class)->handle($invitation, InvitationAccessAuditEvent::PrivateLinkRotated, InvitationAccessAuditActor::System);

        $other = Invitation::factory()->create();
        app(RecordInvitationAccessAudit::class)->handle($other, InvitationAccessAuditEvent::PrivateLinkRotated, InvitationAccessAuditActor::System);
        $other->delete();
        $this->assertDatabaseMissing('invitation_access_audits', ['invitation_id' => $other->id]);

        $this->expectException(LogicException::class);
        $audit->forceFill(['metadata' => ['changed' => 'yes']])->save();
    }

    public function test_audit_failure_rolls_back_claim_and_credential(): void
    {
        [, , $invitation] = $this->managedInvitation();
        $this->app->bind(RecordInvitationAccessAudit::class, fn () => new class extends RecordInvitationAccessAudit
        {
            public function handle(Invitation $invitation, InvitationAccessAuditEvent $event, InvitationAccessAuditActor $actor, array $metadata = [], ?User $user = null): InvitationAccessAudit
            {
                throw new RuntimeException('Forced audit failure.');
            }
        });

        $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertServerError();
        $this->assertDatabaseCount('invitation_browser_credentials', 0);
        $this->assertDatabaseCount('invitation_access_audits', 0);
    }

    /** @return array{Event, User, Invitation} */
    private function managedInvitation(): array
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Audit', 'last_name' => 'Guest']]);

        return [$event, $owner, $invitation->fresh('currentPrivateLink')];
    }
}
