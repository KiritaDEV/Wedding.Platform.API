<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_action_creates_an_event_and_owner_membership_without_changing_platform_role(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, [
            'name' => 'Neil & Hazel',
            'event_date' => '2027-06-12',
            'time_zone' => 'Asia/Manila',
        ]);

        $membership = $event->memberships()->sole();

        $this->assertTrue($event->exists);
        $this->assertIsString($event->id);
        $this->assertTrue(Str::isUlid($event->id));
        $this->assertIsString($membership->id);
        $this->assertTrue(Str::isUlid($membership->id));
        $this->assertTrue($membership->user->is($creator));
        $this->assertSame(EventMembershipRole::Owner, $membership->role);
        $this->assertSame(PlatformRole::User, $creator->fresh()->platform_role);
    }

    public function test_event_and_membership_attributes_are_cast_to_domain_enums(): void
    {
        $event = Event::factory()->create();
        $membership = EventMembership::factory()->for($event)->create([
            'role' => EventMembershipRole::Admin,
        ]);

        $this->assertSame(EventType::Wedding, $event->type);
        $this->assertSame(EventStatus::Active, $event->status);
        $this->assertSame(EventMembershipRole::Admin, $membership->role);
    }

    public function test_users_and_events_support_many_memberships(): void
    {
        $user = User::factory()->create();
        $events = Event::factory()->count(2)->create();

        foreach ($events as $event) {
            EventMembership::factory()->for($event)->for($user)->create();
        }

        $secondUser = User::factory()->create();
        EventMembership::factory()->for($events->first())->for($secondUser)->create();

        $this->assertCount(2, $user->eventMemberships);
        $this->assertCount(2, $user->events);
        $this->assertCount(2, $events->first()->memberships);
        $this->assertCount(2, $events->first()->users);
    }

    public function test_duplicate_memberships_are_rejected_by_the_database(): void
    {
        $membership = EventMembership::factory()->create();

        $this->expectException(QueryException::class);

        EventMembership::factory()->create([
            'event_id' => $membership->event_id,
            'user_id' => $membership->user_id,
        ]);
    }

    public function test_slugs_are_generated_and_disambiguated_for_duplicate_names(): void
    {
        $creator = User::factory()->create();
        $action = app(CreateEvent::class);

        $first = $action->handle($creator, ['name' => 'Neil & Hazel', 'time_zone' => 'UTC']);
        $second = $action->handle($creator, ['name' => 'Neil & Hazel', 'time_zone' => 'UTC']);

        $this->assertSame('neil-hazel', $first->slug);
        $this->assertSame('neil-hazel-2', $second->slug);
    }

    public function test_an_explicit_slug_is_normalized_and_made_unique(): void
    {
        $creator = User::factory()->create();
        $action = app(CreateEvent::class);

        $first = $action->handle($creator, [
            'name' => 'First Event',
            'slug' => 'Our Celebration!',
            'time_zone' => 'UTC',
        ]);
        $second = $action->handle($creator, [
            'name' => 'Second Event',
            'slug' => 'Our Celebration!',
            'time_zone' => 'UTC',
        ]);

        $this->assertSame('our-celebration', $first->slug);
        $this->assertSame('our-celebration-2', $second->slug);
    }
}
