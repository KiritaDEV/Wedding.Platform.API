<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Notifications\RecordManagementNotifications;
use App\Enums\EventMembershipRole;
use App\Enums\UserNotificationSource;
use App\Enums\UserNotificationType;
use App\Invitations\InvitationTrustCookie;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
        $this->withCredentials();
    }

    public function test_private_rsvp_fans_out_received_then_updated_and_ignores_noop(): void
    {
        [$event, $invitation, $cookie, $secret, $owner, $admin] = $this->trustedManagedInvitation();
        $guest = $invitation->guests->first();

        $this->submit($invitation, $cookie, $secret, 'attending')->assertJsonPath('data.confirmation', 'received');
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertSame([UserNotificationType::GuestRsvpReceived], UserNotification::query()->where('recipient_user_id', $owner->id)->pluck('type')->all());
        $this->submit($invitation, $cookie, $secret, 'attending')->assertJsonPath('data.changed', false);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->submit($invitation, $cookie, $secret, 'declined')->assertJsonPath('data.confirmation', 'updated');
        $this->assertDatabaseCount('user_notifications', 4);
        $this->assertDatabaseHas('user_notifications', ['recipient_user_id' => $admin->id, 'type' => 'guest_rsvp_updated']);
        $this->assertDatabaseMissing('user_notifications', ['recipient_user_id' => User::factory()->create()->id]);
        $this->assertSame($guest->invitation->effectiveName(), UserNotification::query()->first()->invitation_name_snapshot);
    }

    public function test_access_request_notifies_once_with_safe_browser_metadata(): void
    {
        [, $invitation, , , $owner] = $this->trustedManagedInvitation();
        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0')
            ->postJson('/api/private-invitations/access-requests', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $notification = UserNotification::query()->where('recipient_user_id', $owner->id)->sole();
        $this->assertSame(UserNotificationType::AccessTransferRequested, $notification->type);
        $this->assertSame(['browserFamily' => 'Chrome', 'platform' => 'Windows'], $notification->metadata);
        $this->assertStringNotContainsString('secret', json_encode($notification->metadata, JSON_THROW_ON_ERROR));

        $credential = $invitation->accessTransferRequests()->sole()->requestedCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);
        $this->withUnencryptedCookie($cookie, $response->getCookie($cookie, false)->getValue())
            ->postJson('/api/private-invitations/access-requests', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $this->assertSame(1, UserNotification::query()->where('recipient_user_id', $owner->id)->count());
    }

    public function test_personal_summary_list_and_read_state_are_isolated(): void
    {
        [$event, $invitation, , , $owner, $admin] = $this->trustedManagedInvitation();
        foreach (range(1, 27) as $index) {
            app(RecordManagementNotifications::class)->handle($event, $invitation, UserNotificationType::GuestRsvpUpdated, UserNotificationSource::RsvpSubmission, (string) Str::ulid());
            $this->travel(1)->second();
        }

        $this->getJson('/api/notifications/summary')->assertUnauthorized();
        $summary = $this->actingAs($owner)->getJson('/api/notifications/summary')->assertOk()
            ->assertJsonPath('data.unreadCount', 27);
        $this->assertNotNull($summary->json('data.latestNotificationId'));
        $first = $this->actingAs($owner)->getJson('/api/notifications')->assertOk()->assertJsonCount(25, 'data');
        $this->assertStringContainsString('"details":{}', $first->getContent());
        $id = $first->json('data.0.id');
        $first->assertJsonMissingPath('data.0.metadata')->assertJsonMissingPath('data.0.sourceId');
        $this->actingAs($admin)->patchJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/notifications/{$id}/read")->assertOk();
        $this->actingAs($owner)->patchJson("/api/notifications/{$id}/read")->assertOk();
        $this->actingAs($owner)->postJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($owner)->getJson('/api/notifications/summary')->assertJsonPath('data.unreadCount', 0);
        $this->actingAs($admin)->getJson('/api/notifications/summary')->assertJsonPath('data.unreadCount', 27);
        $second = $this->actingAs($owner)->getJson('/api/notifications?cursor='.urlencode($first->json('meta.nextCursor')))->assertJsonCount(2, 'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_summary_is_two_indexed_notification_queries_without_body_hydration(): void
    {
        $user = User::factory()->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->getJson('/api/notifications/summary')->assertOk()->assertExactJson(['data' => [
            'unreadCount' => 0,
            'latestNotificationId' => null,
        ]]);

        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'user_notifications'));
        $this->assertCount(2, $queries);
        $this->assertTrue($queries->every(fn (string $query): bool => ! str_contains($query, ' join ')));
    }

    public function test_notification_failure_rolls_back_private_rsvp(): void
    {
        [, $invitation, $cookie, $secret] = $this->trustedManagedInvitation();
        $this->app->bind(RecordManagementNotifications::class, fn () => new class extends RecordManagementNotifications
        {
            public function handle(Event $event, Invitation $invitation, UserNotificationType $type, UserNotificationSource $sourceType, string $sourceId, array $metadata = []): void
            {
                throw new RuntimeException('Forced notification failure.');
            }
        });

        $this->submit($invitation, $cookie, $secret, 'attending')->assertServerError();
        $this->assertDatabaseCount('rsvp_submissions', 0);
        $this->assertNull($invitation->guests->first()->refresh()->rsvp_response);
    }

    public function test_notification_failure_rolls_back_transfer_request(): void
    {
        [, $invitation] = $this->trustedManagedInvitation();
        $this->app->bind(RecordManagementNotifications::class, fn () => new class extends RecordManagementNotifications
        {
            public function handle(Event $event, Invitation $invitation, UserNotificationType $type, UserNotificationSource $sourceType, string $sourceId, array $metadata = []): void
            {
                throw new RuntimeException('Forced notification failure.');
            }
        });

        $this->postJson('/api/private-invitations/access-requests', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertServerError();
        $this->assertDatabaseCount('invitation_access_transfer_requests', 0);
        $this->assertSame(1, $invitation->browserCredentials()->count());
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_snapshots_are_historical_and_notifications_do_not_block_hard_delete(): void
    {
        $event = Event::factory()->create(['name' => 'Original Event']);
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Original', 'last_name' => 'Invitation']]);
        app(RecordManagementNotifications::class)->handle($event, $invitation, UserNotificationType::GuestRsvpReceived, UserNotificationSource::RsvpSubmission, (string) Str::ulid());
        $event->update(['name' => 'Renamed Event']);
        $invitation->update(['custom_name' => 'Renamed Invitation']);

        $notification = UserNotification::query()->sole();
        $this->assertSame('Original Event', $notification->event_name_snapshot);
        $this->assertSame('Original Invitation', $notification->invitation_name_snapshot);
        $this->assertTrue($invitation->fresh('guests')->canPermanentlyDelete());
        $invitation->delete();
        $this->assertDatabaseCount('user_notifications', 0);
    }

    private function trustedManagedInvitation(): array
    {
        $event = Event::factory()->create(['rsvp_is_open' => true, 'time_zone' => 'Asia/Manila']);
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Bruce', 'last_name' => 'Banner']]);
        $claim = $this->postJson('/api/private-invitations/open', ['token' => $invitation->currentPrivateLink->encrypted_token])->assertOk();
        $credential = $invitation->fresh()->currentBrowserCredential;
        $cookie = app(InvitationTrustCookie::class)->name($credential);

        return [$event, $invitation->fresh(['guests', 'currentPrivateLink']), $cookie, $claim->getCookie($cookie, false)->getValue(), $owner, $admin];
    }

    private function submit(Invitation $invitation, string $cookie, string $secret, string $response)
    {
        return $this->withUnencryptedCookie($cookie, $secret)->postJson('/api/private-invitations/rsvp', [
            'token' => $invitation->currentPrivateLink->encrypted_token,
            'responses' => [['guestId' => $invitation->guests->first()->id, 'response' => $response]],
        ]);
    }
}
