<?php

namespace Tests\Feature;

use App\Actions\Invitations\AssignWeddingRole;
use App\Actions\Invitations\CreateCustomWeddingRole;
use App\Actions\Invitations\CreateInvitation;
use App\Enums\EventMembershipRole;
use App\Enums\InvitationStatus;
use App\Invitations\LikePattern;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use App\Models\WeddingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvitationCoordinatorListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept', 'application/json');
    }

    public function test_default_list_returns_all_lifecycles_expanded_pending_rows_and_event_wide_counts(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $active = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana', 'last_name' => 'Cruz', 'relationship' => 'family_member', 'side' => 'bride'],
            ['first_name' => 'Pedro', 'side' => 'groom'],
        ]);
        $inactive = app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria']], 'Inactive Group');
        $inactive->update(['status' => InvitationStatus::Inactive]);
        $builtIn = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        $custom = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        app(AssignWeddingRole::class)->handle($active->guests->first(), $builtIn);
        app(AssignWeddingRole::class)->handle($active->guests->first(), $custom);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.perPage', 25)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.summary.activeInvitations', 1)
            ->assertJsonPath('meta.summary.guests', 2)
            ->assertJsonPath('meta.summary.attending', 0)
            ->assertJsonPath('meta.summary.declined', 0)
            ->assertJsonPath('meta.summary.pending', 2)
            ->assertJsonPath('meta.lifecycleCounts.all', 2)
            ->assertJsonPath('meta.lifecycleCounts.active', 1)
            ->assertJsonPath('meta.lifecycleCounts.inactive', 1);

        $activeRow = collect($response->json('data'))->firstWhere('id', $active->id);
        $this->assertSame(2, $activeRow['guestCount']);
        $this->assertSame(2, $activeRow['totalGuestCount']);
        $this->assertSame(['status' => 'pending', 'attendingCount' => 0, 'declinedCount' => 0, 'pendingCount' => 2], $activeRow['rsvp']);
        $this->assertNull($activeRow['lastResponse']);
        $this->assertNull($activeRow['guests'][0]['rsvpResponse']);
        $this->assertSame('pending', $activeRow['guests'][0]['rsvpStatus']);
        $this->assertCount(2, $activeRow['guests'][0]['weddingRoles']);
        $this->assertArrayNotHasKey('normalizedFirstName', $activeRow['guests'][0]);
        $this->assertArrayNotHasKey('scopeKey', $activeRow['guests'][0]['weddingRoles'][0]);
    }

    public function test_management_heading_count_is_total_while_names_rsvp_and_event_summary_remain_active_only(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $twoActive = app(CreateInvitation::class)->handle($event, [['first_name' => 'Tony'], ['first_name' => 'Steve']]);
        $fourRetained = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Yelena'], ['first_name' => 'John'], ['first_name' => 'James'], ['first_name' => 'Bucky'],
        ], 'Avengers');
        $threeRetained = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Active'], ['first_name' => 'Inactive One'], ['first_name' => 'Inactive Two'],
        ]);
        $fourRetained->guests[0]->update(['rsvp_response' => 'declined']);
        $fourRetained->guests[3]->update(['status' => 'inactive']);
        $threeRetained->guests[0]->update(['rsvp_response' => 'attending']);
        $threeRetained->guests[1]->update(['status' => 'inactive']);
        $threeRetained->guests[2]->update(['status' => 'inactive']);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame(2, $rows[$twoActive->id]['totalGuestCount']);
        $this->assertSame(4, $rows[$fourRetained->id]['totalGuestCount']);
        $this->assertSame(3, $rows[$fourRetained->id]['guestCount']);
        $this->assertSame(['status' => 'partial', 'attendingCount' => 0, 'declinedCount' => 1, 'pendingCount' => 2], $rows[$fourRetained->id]['rsvp']);
        $this->assertSame(['status' => 'complete', 'attendingCount' => 1, 'declinedCount' => 0, 'pendingCount' => 0], $rows[$threeRetained->id]['rsvp']);
        $this->assertSame(3, $rows[$threeRetained->id]['totalGuestCount']);
        $this->assertSame(1, $rows[$threeRetained->id]['guestCount']);
        $this->assertSame('Active', $rows[$threeRetained->id]['effectiveName']);
        $this->assertSame(6, $response->json('meta.summary.guests'));
    }

    public function test_search_matches_custom_name_guest_names_and_assigned_builtin_or_custom_roles_only(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $customNamed = app(CreateInvitation::class)->handle($event, [['first_name' => 'Unrelated']], 'Barnedo Family');
        $guestNamed = app(CreateInvitation::class)->handle($event, [['first_name' => 'Neil', 'last_name' => 'Santos']]);
        $roleInvitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Role Person']]);
        $cord = WeddingRole::query()->where('key', 'cord_sponsor')->firstOrFail();
        $reader = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        app(CreateCustomWeddingRole::class)->handle($event, 'Unused Role');
        app(AssignWeddingRole::class)->handle($roleInvitation->guests->first(), $cord);
        app(AssignWeddingRole::class)->handle($roleInvitation->guests->first(), $reader);

        foreach ([
            'Barnedo Family' => $customNamed->id,
            'Neil' => $guestNamed->id,
            'Santos' => $guestNamed->id,
            'Neil Santos' => $guestNamed->id,
            'Cord Sponsor' => $roleInvitation->id,
            'Reader' => $roleInvitation->id,
        ] as $term => $expectedId) {
            $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations?q=".urlencode($term))
                ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $expectedId);
        }
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations?q=Unused%20Role")
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_search_uses_portable_bound_literal_wildcard_patterns(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $percent = app(CreateInvitation::class)->handle($event, [['first_name' => 'Percent Person']], 'Reception 100%');
        $underscore = app(CreateInvitation::class)->handle($event, [['first_name' => 'guest_name']]);
        $bang = app(CreateInvitation::class)->handle($event, [['first_name' => 'Wow!']]);
        $roleInvitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Role Person']]);
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Role_100%!');
        app(AssignWeddingRole::class)->handle($roleInvitation->guests->first(), $role);
        app(CreateInvitation::class)->handle($event, [['first_name' => 'Ordinary']]);
        $base = "/api/events/{$event->id}/invitations?q=";

        $this->assertEqualsCanonicalizing([$percent->id, $roleInvitation->id], $this->ids($owner, $base.urlencode('%')));
        $this->assertEqualsCanonicalizing([$underscore->id, $roleInvitation->id], $this->ids($owner, $base.urlencode('_')));
        $this->assertEqualsCanonicalizing([$bang->id, $roleInvitation->id], $this->ids($owner, $base.urlencode('!')));
        $this->assertEqualsCanonicalizing([$percent->id, $roleInvitation->id], $this->ids($owner, $base.urlencode('100%')));
        $this->assertSame([$underscore->id], $this->ids($owner, $base.urlencode('guest_name')));

        $this->assertSame('%100!%%', LikePattern::contains('100%'));
        $this->assertSame('%guest!_name%', LikePattern::contains('guest_name'));
        $this->assertSame('%wow!!%', LikePattern::contains('wow!'));
        $this->assertSame("normalized_name LIKE ? ESCAPE '!'", LikePattern::clause('normalized_name'));
        $this->assertStringNotContainsString("ESCAPE '\\\\'", LikePattern::clause('normalized_name'));
    }

    public function test_structured_filter_groups_match_independently_and_compose_with_search(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $invitation = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana', 'last_name' => 'Santos', 'relationship' => 'friend', 'side' => 'bride'],
            ['first_name' => 'Pedro', 'relationship' => 'colleague', 'side' => 'groom'],
        ]);
        $bridesmaid = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        $cord = WeddingRole::query()->where('key', 'cord_sponsor')->firstOrFail();
        app(AssignWeddingRole::class)->handle($invitation->guests->firstWhere('first_name', 'Ana'), $bridesmaid);
        app(AssignWeddingRole::class)->handle($invitation->guests->firstWhere('first_name', 'Pedro'), $cord);

        $base = "/api/events/{$event->id}/invitations";
        $this->actingAs($owner)->getJson("{$base}?sides[]=bride&roleIds[]={$cord->id}")
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($owner)->getJson("{$base}?relationships[]=friend&sides[]=bride&roleIds[]={$bridesmaid->id}&rsvpStatuses[]=pending")
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($owner)->getJson("{$base}?q=Pedro&sides[]=bride&roleIds[]={$bridesmaid->id}")
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($owner)->getJson("{$base}?rsvpStatuses[]=attending")->assertUnprocessable();
        $this->actingAs($owner)->getJson("{$base}?guestResponses[]=partial")->assertUnprocessable();
    }

    public function test_rsvp_filter_uses_the_same_invitation_status_as_rows_without_changing_guest_totals(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $pending = app(CreateInvitation::class)->handle($event, [['first_name' => 'Pending One'], ['first_name' => 'Pending Two']]);
        $partial = app(CreateInvitation::class)->handle($event, [['first_name' => 'Partial One'], ['first_name' => 'Partial Two']]);
        $complete = app(CreateInvitation::class)->handle($event, [['first_name' => 'Complete One'], ['first_name' => 'Complete Two']]);
        $partial->guests[0]->update(['rsvp_response' => 'attending']);
        $complete->guests[0]->update(['rsvp_response' => 'attending']);
        $complete->guests[1]->update(['rsvp_response' => 'declined']);
        $base = "/api/events/{$event->id}/invitations";

        foreach (['pending' => $pending->id, 'partial' => $partial->id, 'complete' => $complete->id] as $status => $id) {
            $response = $this->actingAs($owner)->getJson("{$base}?rsvpStatuses[]={$status}")->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('data.0.rsvp.status', $status);
            $response->assertJsonPath('meta.summary.guests', 6)
                ->assertJsonPath('meta.summary.attending', 2)
                ->assertJsonPath('meta.summary.declined', 1)
                ->assertJsonPath('meta.summary.pending', 3);
        }
    }

    public function test_multi_select_groups_use_or_within_and_independent_and_across_groups(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $first = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Parent Guest', 'relationship' => 'parent', 'side' => 'bride'],
            ['first_name' => 'Role Guest', 'relationship' => 'colleague', 'side' => 'groom'],
        ]);
        $second = app(CreateInvitation::class)->handle($event, [['first_name' => 'Friend Guest', 'relationship' => 'friend', 'side' => 'groom']]);
        $third = app(CreateInvitation::class)->handle($event, [['first_name' => 'Other Guest', 'relationship' => 'guest_other', 'side' => 'unspecified']]);
        $groomsman = WeddingRole::query()->where('key', 'groomsman')->firstOrFail();
        $bridesmaid = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        $custom = app(CreateCustomWeddingRole::class)->handle($event, 'Custom Reader');
        app(AssignWeddingRole::class)->handle($first->guests[1], $groomsman);
        app(AssignWeddingRole::class)->handle($second->guests[0], $bridesmaid);
        app(AssignWeddingRole::class)->handle($third->guests[0], $custom);
        $first->guests[0]->update(['rsvp_response' => 'attending']);
        $second->guests[0]->update(['rsvp_response' => 'declined']);
        $base = "/api/events/{$event->id}/invitations";

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->ids($owner, "{$base}?relationships[]=parent&relationships[]=friend"));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->ids($owner, "{$base}?sides[]=bride&sides[]=groom"));
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], $this->ids($owner, "{$base}?roleIds[]={$groomsman->id}&roleIds[]={$bridesmaid->id}&roleIds[]={$custom->id}"));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->ids($owner, "{$base}?guestResponses[]=attending&guestResponses[]=declined"));
        $this->assertEqualsCanonicalizing([$first->id, $third->id], $this->ids($owner, "{$base}?guestResponses[]=pending"));
        $this->assertEqualsCanonicalizing([$first->id, $third->id], $this->ids($owner, "{$base}?rsvpStatuses[]=partial&rsvpStatuses[]=pending"));
        $this->assertSame([$first->id], $this->ids($owner, "{$base}?relationships[]=parent&roleIds[]={$groomsman->id}&guestResponses[]=attending"));
        $this->actingAs($owner)->getJson("{$base}?relationships[]=parent&relationships[]=bad")->assertUnprocessable();
        $this->actingAs($owner)->getJson("{$base}?guestResponses[]=complete")->assertUnprocessable();
    }

    public function test_lifecycle_and_role_validation_are_strict_and_summary_ignores_query(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        app(CreateInvitation::class)->handle($event, [['first_name' => 'Active']]);
        $inactive = app(CreateInvitation::class)->handle($event, [['first_name' => 'Inactive']]);
        $inactive->update(['status' => InvitationStatus::Inactive]);
        $foreignRole = app(CreateCustomWeddingRole::class)->handle(Event::factory()->create(), 'Foreign');
        $base = "/api/events/{$event->id}/invitations";

        $this->actingAs($owner)->getJson("{$base}?lifecycle=active&q=NoMatch")
            ->assertOk()->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.summary.activeInvitations', 1)
            ->assertJsonPath('meta.summary.guests', 1)
            ->assertJsonPath('meta.lifecycleCounts.all', 2);
        $this->actingAs($owner)->getJson("{$base}?lifecycle=inactive")
            ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.status', 'inactive');
        $this->actingAs($owner)->getJson("{$base}?lifecycle=bad")->assertUnprocessable();
        $this->actingAs($owner)->getJson("{$base}?sort=bad")->assertUnprocessable();
        $this->actingAs($owner)->getJson("{$base}?page=0")->assertUnprocessable();
        $this->actingAs($owner)->getJson("{$base}?roleIds[]={$foreignRole->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('roleIds');
    }

    public function test_sort_modes_use_effective_names_and_stable_no_response_fallback(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $custom = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ignored']], 'Aardvark');
        $two = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Aaron'], ['first_name' => 'Zulu'],
        ]);
        $three = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Alpha'], ['first_name' => 'Omega'], ['first_name' => 'Gamma'],
        ]);
        $single = app(CreateInvitation::class)->handle($event, [['first_name' => 'Beta']]);
        $custom->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
        $two->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $three->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $single->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $base = "/api/events/{$event->id}/invitations";

        $this->assertSame([$single->id, $three->id, $two->id, $custom->id], $this->ids($owner, "{$base}?sort=recently_added"));
        $this->assertSame([$custom->id, $two->id, $three->id, $single->id], $this->ids($owner, "{$base}?sort=invitation_asc"));
        $this->assertSame([$single->id, $three->id, $two->id, $custom->id], $this->ids($owner, "{$base}?sort=invitation_desc"));
        $this->assertSame([$single->id, $three->id, $two->id, $custom->id], $this->ids($owner, "{$base}?sort=last_response_desc"));
        $this->assertSame([$single->id, $three->id, $two->id, $custom->id], $this->ids($owner, "{$base}?sort=last_response_asc"));

        $rows = $this->actingAs($owner)->getJson("{$base}?sort=invitation_asc")->assertOk()->json('data');
        $this->assertSame('Aardvark', $rows[0]['effectiveName']);
        $this->assertSame('Aaron & Zulu', $rows[1]['effectiveName']);
        $this->assertSame('Alpha, Omega + 1 guest', $rows[2]['effectiveName']);
    }

    public function test_detail_and_list_use_the_same_stably_ordered_effective_name(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $invitation = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'First'], ['first_name' => 'Second'], ['first_name' => 'Third'], ['first_name' => 'Fourth'],
        ]);
        $guests = $invitation->guests->values();
        $guests[3]->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $guests[2]->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $detailName = $this->actingAs($owner)
            ->getJson("/api/events/{$event->id}/invitations/{$invitation->id}")
            ->assertOk()->json('data.displayName');
        $listName = $this->actingAs($owner)
            ->getJson("/api/events/{$event->id}/invitations")
            ->assertOk()->json('data.0.effectiveName');

        $this->assertSame('Fourth, Third + 2 guests', $detailName);
        $this->assertSame($detailName, $listName);
    }

    public function test_pagination_search_and_filters_run_before_page_slicing_without_duplicate_rows(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $role = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        for ($index = 1; $index <= 27; $index++) {
            $invitation = app(CreateInvitation::class)->handle($event, [[
                'first_name' => sprintf('Guest %02d', $index),
                'side' => $index > 25 ? 'bride' : 'groom',
            ]]);
            app(AssignWeddingRole::class)->handle($invitation->guests->first(), $role);
        }
        $base = "/api/events/{$event->id}/invitations";

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.pagination.total', 27)->assertJsonPath('meta.pagination.lastPage', 2)
            ->assertJsonPath('meta.summary.activeInvitations', 27);
        $this->actingAs($owner)->getJson("{$base}?page=2")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($owner)->getJson("{$base}?sides[]=bride")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.summary.activeInvitations', 27);
        $this->actingAs($owner)->getJson("{$base}?q=Guest%2027")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($owner)->getJson("{$base}?roleIds[]={$role->id}")->assertOk()
            ->assertJsonPath('meta.pagination.total', 27);
    }

    public function test_query_count_is_constant_for_eager_loaded_guest_roles(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        $role = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        foreach (range(1, 25) as $index) {
            $invitation = app(CreateInvitation::class)->handle($event, [
                ['first_name' => "Guest {$index} A"], ['first_name' => "Guest {$index} B"],
            ]);
            foreach ($invitation->guests as $guest) {
                app(AssignWeddingRole::class)->handle($guest, $role);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/invitations")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $count);
    }

    public function test_authorization_and_event_isolation(): void
    {
        [$event, $owner] = $this->eventMember(EventMembershipRole::Owner);
        [, $admin] = $this->eventMember(EventMembershipRole::Admin, $event);
        app(CreateInvitation::class)->handle($event, [['first_name' => 'Visible']]);
        $foreign = Event::factory()->create();
        app(CreateInvitation::class)->handle($foreign, [['first_name' => 'Hidden']]);
        $outsider = User::factory()->create();
        $super = User::factory()->superAdmin()->create();
        $url = "/api/events/{$event->id}/invitations";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($super)->getJson($url)->assertOk();
    }

    private function ids(User $user, string $url): array
    {
        return $this->actingAs($user)->getJson($url)->assertOk()->json('data.*.id');
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
