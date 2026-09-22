<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationStatus;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationOptionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_are_event_scoped_lightweight_and_effective_name_ordered(): void
    {
        [$event, $owner] = $this->eventMember();
        $zulu = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ignored']], 'Zulu');
        $alpha = app(CreateInvitation::class)->handle($event, [['first_name' => 'Alpha'], ['first_name' => 'Beta']]);
        $zulu->update(['status' => InvitationStatus::Inactive]);
        app(CreateInvitation::class)->handle(Event::factory()->create(), [['first_name' => 'Foreign']]);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitation-options")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$alpha->id, $zulu->id], $response->json('data.*.id'));
        $response->assertJsonPath('data.0.effectiveName', 'Alpha & Beta')->assertJsonPath('data.0.guestCount', 2)
            ->assertJsonPath('data.1.status', 'inactive');
        $this->assertSame(['id', 'effectiveName', 'status', 'guestCount'], array_keys($response->json('data.0')));
    }

    public function test_options_use_event_authorization(): void
    {
        [$event, $owner] = $this->eventMember();
        $admin = User::factory()->create();
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $outsider = User::factory()->create();
        $super = User::factory()->superAdmin()->create();
        $url = "/api/events/{$event->id}/invitation-options";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($super)->getJson($url)->assertOk();
    }

    /** @return array{Event, User} */
    private function eventMember(): array
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        EventMembership::factory()->for($event)->for($user)->create(['role' => EventMembershipRole::Owner]);

        return [$event, $user];
    }
}
