<?php

namespace Tests\Feature;

use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRsvpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_requires_and_persists_an_iana_timezone_and_defaults_closed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/events', ['name' => 'No zone', 'type' => 'wedding'])
            ->assertUnprocessable()->assertJsonValidationErrors('timeZone');
        $this->actingAs($user)->postJson('/api/events', ['name' => 'Bad zone', 'type' => 'wedding', 'timeZone' => 'GMT+8'])
            ->assertUnprocessable()->assertJsonValidationErrors('timeZone');

        $this->actingAs($user)->postJson('/api/events', ['name' => 'Wedding', 'type' => 'wedding', 'timeZone' => 'Asia/Manila'])
            ->assertCreated()->assertJsonPath('data.timeZone', 'Asia/Manila')->assertJsonPath('data.rsvpIsOpen', false);
    }

    public function test_date_only_deadline_uses_the_event_timezone_including_dst(): void
    {
        $event = Event::factory()->create(['time_zone' => 'America/New_York', 'rsvp_is_open' => true, 'rsvp_deadline' => '2026-11-01']);

        $this->assertTrue($event->isRsvpEffectivelyOpen(CarbonImmutable::parse('2026-11-02T04:59:59Z')));
        $this->assertFalse($event->isRsvpEffectivelyOpen(CarbonImmutable::parse('2026-11-02T05:00:00Z')));
        $event->rsvp_is_open = false;
        $this->assertFalse($event->isRsvpEffectivelyOpen(CarbonImmutable::parse('2026-11-01T12:00:00Z')));
    }

    public function test_owner_and_admin_can_set_change_and_clear_rsvp_settings(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $event->memberships()->createMany([
            ['user_id' => $owner->id, 'role' => EventMembershipRole::Owner],
            ['user_id' => $admin->id, 'role' => EventMembershipRole::Admin],
        ]);
        $url = "/api/events/{$event->id}/rsvp-settings";
        $this->actingAs($owner)->putJson($url, ['isOpen' => true, 'deadline' => '2027-01-10'])->assertOk();
        $this->actingAs($admin)->putJson($url, ['isOpen' => false, 'deadline' => null])->assertOk()
            ->assertJsonPath('data.rsvpDeadline', null);
        $this->actingAs(User::factory()->create())->putJson($url, ['isOpen' => true, 'deadline' => null])->assertForbidden();
    }
}
