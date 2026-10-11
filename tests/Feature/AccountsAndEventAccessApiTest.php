<?php

namespace Tests\Feature;

use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountsAndEventAccessApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost',
        ]);
    }

    public function test_registration_creates_and_authenticates_only_a_normal_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Neil',
            'email' => ' NEIL@EXAMPLE.COM ',
            'password' => 'password123',
            'passwordConfirmation' => 'password123',
            'platformRole' => 'superAdmin',
            'platform_role' => 'superAdmin',
        ]);

        $response->assertCreated()
            ->assertExactJson(['data' => [
                'id' => User::query()->sole()->id,
                'name' => 'Neil',
                'email' => 'neil@example.com',
                'platformRole' => 'user',
            ]]);

        $user = User::query()->sole();
        $this->assertTrue(Str::isUlid($user->id));
        $this->assertSame(PlatformRole::User, $user->platform_role);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_validation_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Another User',
            'email' => 'EXISTING@example.com',
            'password' => 'password123',
            'passwordConfirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_uses_the_session_and_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson('/api/auth/login', [
            'email' => strtoupper($user->email),
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_current_user_is_safe_and_requires_authentication(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/auth/me')->assertUnauthorized();

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'platformRole']])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);

        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_an_event_as_owner(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/events', [
            'name' => 'Neil & Hazel',
            'type' => 'wedding',
            'eventDate' => '2027-12-22',
            'timeZone' => 'Asia/Manila',
            'membershipRole' => 'admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'neil-hazel')
            ->assertJsonPath('data.membershipRole', 'owner')
            ->assertJsonPath('data.eventDate', '2027-12-22');

        $event = Event::query()->sole();
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
        $this->assertSame(PlatformRole::User, $user->fresh()->platform_role);
    }

    public function test_event_creation_validates_type_and_authentication(): void
    {
        $payload = ['name' => 'Party', 'type' => 'birthday'];

        $this->postJson('/api/events', $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_my_events_contains_owner_and_admin_events_only_in_updated_order(): void
    {
        $user = User::factory()->create();
        $owned = Event::factory()->create(['updated_at' => now()->subDay()]);
        $administered = Event::factory()->create(['updated_at' => now()]);
        $unrelated = Event::factory()->create();
        EventMembership::factory()->for($owned)->for($user)->create(['role' => EventMembershipRole::Owner]);
        EventMembership::factory()->for($administered)->for($user)->create(['role' => EventMembershipRole::Admin]);

        $response = $this->actingAs($user)->getJson('/api/events')->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $administered->id)
            ->assertJsonPath('data.0.membershipRole', 'admin')
            ->assertJsonPath('data.1.id', $owned->id)
            ->assertJsonPath('data.1.membershipRole', 'owner');
        $this->assertNotContains($unrelated->id, $response->json('data.*.id'));
    }

    public function test_event_reads_require_membership_with_super_admin_bypass(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $unrelated = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);

        $this->getJson("/api/events/{$event->id}")->assertUnauthorized();
        $this->actingAs($owner)->getJson("/api/events/{$event->id}")
            ->assertOk()->assertJsonPath('data.membershipRole', 'owner');
        $this->actingAs($admin)->getJson("/api/events/{$event->id}")
            ->assertOk()->assertJsonPath('data.membershipRole', 'admin');
        $this->actingAs($unrelated)->getJson("/api/events/{$event->id}")->assertForbidden();
        $this->actingAs($superAdmin)->getJson("/api/events/{$event->id}")
            ->assertOk()->assertJsonPath('data.membershipRole', null);
        $this->actingAs($owner)->getJson('/api/events/not-a-ulid')->assertNotFound();
    }
}
