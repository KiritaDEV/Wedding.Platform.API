<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EventTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_existing_event_time_is_optional_but_timezone_is_canonical_and_does_not_require_a_website(): void
    {
        [$event, $owner] = $this->eventWithOwner(['event_date' => '2026-12-22']);

        $this->assertNull($event->start_time);
        $this->assertSame('UTC', $event->time_zone);
        $this->assertNull($event->startsAtUtc());
        $this->assertFalse($event->website()->exists());
        $this->actingAs($owner)->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.eventDate', '2026-12-22')
            ->assertJsonPath('data.startTime', null)
            ->assertJsonPath('data.timeZone', 'UTC')
            ->assertJsonPath('data.startsAtUtc', null);
    }

    public function test_timing_update_persists_local_values_and_derives_manila_utc_instant(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/timing", [
            'eventDate' => '2026-12-22',
            'startTime' => '15:00',
            'timeZone' => 'Asia/Manila',
        ])->assertOk()
            ->assertJsonPath('data.eventDate', '2026-12-22')
            ->assertJsonPath('data.startTime', '15:00')
            ->assertJsonPath('data.timeZone', 'Asia/Manila')
            ->assertJsonPath('data.startsAtUtc', '2026-12-22T07:00:00Z');

        $this->assertSame('15:00', substr($event->refresh()->start_time, 0, 5));
        $this->assertSame('Asia/Manila', $event->time_zone);
    }

    public function test_derived_instant_uses_dst_rules_and_is_null_when_incomplete(): void
    {
        $london = Event::factory()->create(['event_date' => '2026-07-15', 'start_time' => '15:00', 'time_zone' => 'Europe/London']);
        $newYork = Event::factory()->create(['event_date' => '2026-01-15', 'start_time' => '15:00', 'time_zone' => 'America/New_York']);
        $incomplete = Event::factory()->create(['event_date' => '2026-12-22', 'start_time' => null, 'time_zone' => 'UTC']);

        $this->assertSame('2026-07-15T14:00:00Z', $london->startsAtUtc()?->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-01-15T20:00:00Z', $newYork->startsAtUtc()?->format('Y-m-d\TH:i:s\Z'));
        $this->assertNull($incomplete->startsAtUtc());
    }

    public function test_invalid_time_zone_time_and_dst_gap_are_rejected_atomically(): void
    {
        [$event, $owner] = $this->eventWithOwner(['event_date' => '2026-12-22', 'start_time' => '15:00', 'time_zone' => 'Asia/Manila']);
        $url = "/api/events/{$event->id}/timing";

        foreach (['GMT+8', 'PST', 'Definitely/NotAZone'] as $zone) {
            $this->actingAs($owner)->putJson($url, ['eventDate' => '2027-01-01', 'startTime' => '16:00', 'timeZone' => $zone])
                ->assertUnprocessable()->assertJsonValidationErrors('timeZone');
        }
        $this->actingAs($owner)->putJson($url, ['eventDate' => '2027-01-01', 'startTime' => '25:00', 'timeZone' => 'Asia/Manila'])
            ->assertUnprocessable()->assertJsonValidationErrors('startTime');
        $this->actingAs($owner)->putJson($url, ['eventDate' => '2026-03-08', 'startTime' => '02:30', 'timeZone' => 'America/New_York'])
            ->assertUnprocessable()->assertJsonValidationErrors('startTime');

        $event->refresh();
        $this->assertSame('2026-12-22', $event->event_date->toDateString());
        $this->assertSame('15:00', substr($event->start_time, 0, 5));
        $this->assertSame('Asia/Manila', $event->time_zone);
    }

    public function test_nullable_date_and_time_are_supported_while_timezone_remains_required(): void
    {
        [$event, $owner] = $this->eventWithOwner(['event_date' => '2026-12-22']);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/timing", [
            'eventDate' => '2026-12-22',
            'startTime' => '15:00',
            'timeZone' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('timeZone');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/timing", [
            'eventDate' => '2026-12-22',
            'startTime' => null,
            'timeZone' => 'America/Bogota',
        ])->assertOk()->assertJsonPath('data.startsAtUtc', null);
    }

    public function test_timing_authorization_matches_event_membership_policy(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $admin = User::factory()->create();
        $event->memberships()->create(['user_id' => $admin->id, 'role' => EventMembershipRole::Admin]);
        $other = User::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $url = "/api/events/{$event->id}/timing";
        $payload = ['eventDate' => '2026-12-22', 'startTime' => null, 'timeZone' => 'UTC'];

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs($other)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($admin)->putJson($url, $payload)->assertOk();
        $this->actingAs($owner)->putJson($url, $payload)->assertOk();
        $this->actingAs($superAdmin)->putJson($url, $payload)->assertOk();
    }

    public function test_authenticated_time_zone_capability_uses_php_iana_database(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/time-zones')->assertUnauthorized();
        $response = $this->actingAs($user)->getJson('/api/time-zones')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        foreach (['America/Bogota', 'America/New_York', 'Asia/Manila', 'Europe/London'] as $identifier) {
            $this->assertTrue($ids->contains($identifier));
        }
        $this->assertFalse($ids->contains('PST'));
        $this->assertSame($ids->sort()->values()->all(), $ids->values()->all());
        $this->assertSame($ids->unique()->count(), $ids->count());
    }

    public function test_timing_migration_rollback_and_reapply_preserves_existing_event_date(): void
    {
        $event = Event::factory()->create(['event_date' => '2026-12-22']);
        $migration = require database_path('migrations/2026_08_16_000000_add_timing_to_events.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('events', 'start_time'));
        $this->assertSame('2026-12-22', Event::query()->findOrFail($event->id)->event_date->toDateString());

        $migration->up();
        $event = Event::query()->findOrFail($event->id);
        $this->assertSame('2026-12-22', $event->event_date->toDateString());
        $this->assertNull($event->start_time);
        $this->assertNull($event->time_zone);
    }

    /** @param array<string, mixed> $attributes
     * @return array{Event, User}
     */
    private function eventWithOwner(array $attributes = []): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => 'Timing Test', 'time_zone' => 'UTC', ...$attributes]), $owner];
    }
}
