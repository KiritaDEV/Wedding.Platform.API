<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateCustomWeddingRole;
use App\Enums\EventMembershipRole;
use App\Invitations\BuiltinWeddingRoles;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeddingRoleCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_builtins_in_canonical_order_then_event_custom_roles_alphabetically(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        app(CreateCustomWeddingRole::class)->handle($event, 'Usher');
        app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        $foreign = app(CreateCustomWeddingRole::class)->handle(Event::factory()->create(), 'Foreign');

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/wedding-roles")->assertOk();
        $roles = $response->json('data');

        $this->assertSame(array_keys(BuiltinWeddingRoles::all()), array_column(array_slice($roles, 0, 13), 'key'));
        $this->assertSame(['Reader', 'Usher'], array_column(array_slice($roles, 13), 'name'));
        $this->assertNotContains($foreign->id, array_column($roles, 'id'));
        $this->assertSame(['id', 'key', 'name', 'isBuiltin'], array_keys($roles[0]));
        $this->assertArrayNotHasKey('normalizedName', $roles[0]);
        $this->assertArrayNotHasKey('scopeKey', $roles[0]);
    }

    public function test_catalog_uses_existing_event_authorization_boundary(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        [, $admin] = $this->eventMember(EventMembershipRole::Admin, $event);
        $outsider = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $url = "/api/events/{$event->id}/wedding-roles";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($superAdmin)->getJson($url)->assertOk();
    }

    /** @return array{Event, User} */
    private function eventMember(EventMembershipRole $role, ?Event $event = null): array
    {
        $event ??= Event::factory()->create();
        $user = User::factory()->create();
        EventMembership::factory()->for($event)->for($user)->create(['role' => $role]);

        return [$event, $user];
    }
}
