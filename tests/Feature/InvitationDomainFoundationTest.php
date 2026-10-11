<?php

namespace Tests\Feature;

use App\Actions\Invitations\AssignWeddingRole;
use App\Actions\Invitations\CreateCustomWeddingRole;
use App\Actions\Invitations\CreateInvitation;
use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use App\Enums\InvitationStatus;
use App\Invitations\BuiltinWeddingRoles;
use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\WeddingRole;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class InvitationDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_has_ulid_event_ownership_lifecycle_and_nullable_custom_name(): void
    {
        $event = Event::factory()->create();
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria']]);

        $this->assertTrue(Str::isUlid($invitation->id));
        $this->assertTrue($invitation->event->is($event));
        $this->assertSame(InvitationStatus::Active, $invitation->status);
        $this->assertNull($invitation->custom_name);

        $invitation->update(['status' => InvitationStatus::Inactive]);
        $this->assertSame(InvitationStatus::Inactive, $invitation->fresh()->status);
    }

    public function test_invitation_creation_requires_a_guest_and_effective_name_is_not_persisted(): void
    {
        $event = Event::factory()->create();

        try {
            app(CreateInvitation::class)->handle($event, []);
            $this->fail('An empty Invitation should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('invitations', 0);
        }

        $invitation = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana', 'last_name' => 'Cruz'],
            ['first_name' => 'Pedro'],
        ]);
        $this->assertSame('Ana Cruz & Pedro', $invitation->effectiveName());
        $invitation->update(['custom_name' => '  Ceremony   Party  ']);
        $this->assertSame('Ceremony Party', $invitation->fresh()->effectiveName());
        $this->assertFalse(Schema::hasColumn('invitations', 'effective_name'));
    }

    public function test_effective_name_uses_the_bounded_canonical_guest_format_and_stable_order(): void
    {
        $event = Event::factory()->create();

        $one = app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria', 'last_name' => null]]);
        $two = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Neil', 'last_name' => 'Barnedo'],
            ['first_name' => 'Hazel', 'last_name' => 'Barnedo'],
        ]);
        $three = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Ana'], ['first_name' => 'Pedro'], ['first_name' => 'Liza'],
        ]);
        $four = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'First'], ['first_name' => 'Second'], ['first_name' => 'Third'], ['first_name' => 'Fourth'],
        ]);
        $large = app(CreateInvitation::class)->handle($event, collect(range(1, 10))
            ->map(fn (int $number): array => ['first_name' => "Large {$number}"])
            ->all());
        $custom = app(CreateInvitation::class)->handle($event, [
            ['first_name' => 'Hidden One'], ['first_name' => 'Hidden Two'], ['first_name' => 'Hidden Three'],
        ], 'Custom Invitation');

        $orderedGuests = $four->guests->values();
        $orderedGuests[3]->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $orderedGuests[2]->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->assertSame('Maria', $one->effectiveName());
        $this->assertSame('Neil Barnedo & Hazel Barnedo', $two->effectiveName());
        $this->assertSame('Ana, Pedro + 1 guest', $three->effectiveName());
        $this->assertSame('Fourth, Third + 2 guests', $four->fresh()->effectiveName());
        $this->assertSame('Large 1, Large 2 + 8 guests', $large->effectiveName());
        $this->assertSame('Custom Invitation', $custom->effectiveName());
    }

    public function test_guest_has_stable_identity_ownership_defaults_and_nullable_last_name(): void
    {
        $event = Event::factory()->create();
        $guest = app(CreateInvitation::class)->handle($event, [['first_name' => 'Maria', 'last_name' => '']])->guests->first();

        $this->assertTrue(Str::isUlid($guest->id));
        $this->assertSame($event->id, $guest->event_id);
        $this->assertSame(GuestRelationship::GuestOther, $guest->relationship);
        $this->assertSame(GuestSide::Unspecified, $guest->side);
        $this->assertNull($guest->last_name);
        $this->assertSame('', $guest->normalized_last_name);
    }

    public function test_name_normalization_collapses_whitespace_and_case_but_preserves_punctuation_and_accents(): void
    {
        $this->assertSame('neil barnedo', NameNormalizer::identity(' Neil   Barnedo '));
        $this->assertSame('', NameNormalizer::nullableIdentity(null));
        $this->assertSame('', NameNormalizer::nullableIdentity('  '));
        $this->assertNotSame(NameNormalizer::identity('Anne-Marie'), NameNormalizer::identity('Anne Marie'));
        $this->assertNotSame(NameNormalizer::identity("O'Connor"), NameNormalizer::identity('OConnor'));
        $this->assertNotSame(NameNormalizer::identity('José'), NameNormalizer::identity('Jose'));
    }

    public function test_normalized_duplicate_cannot_coexist_in_one_event_but_can_in_different_events(): void
    {
        $event = Event::factory()->create();
        app(CreateInvitation::class)->handle($event, [['first_name' => ' Neil ', 'last_name' => ' Barnedo ']]);

        $this->expectException(ValidationException::class);
        app(CreateInvitation::class)->handle($event, [['first_name' => 'neil', 'last_name' => 'BARNEDO']]);
    }

    public function test_same_normalized_guest_name_is_allowed_in_different_events_and_accents_are_distinct(): void
    {
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        app(CreateInvitation::class)->handle($first, [['first_name' => 'Maria']]);
        app(CreateInvitation::class)->handle($second, [['first_name' => ' maria ', 'last_name' => '']]);
        app(CreateInvitation::class)->handle($first, [['first_name' => 'María']]);

        $this->assertDatabaseCount('guests', 3);
    }

    public function test_builtin_roles_have_stable_catalog_identifiers_and_custom_roles_are_event_scoped(): void
    {
        $this->assertDatabaseCount('wedding_roles', 13);
        foreach (BuiltinWeddingRoles::all() as $key => $definition) {
            $this->assertTrue(Str::isUlid($definition['id']));
            $this->assertDatabaseHas('wedding_roles', [
                'id' => $definition['id'], 'key' => $key, 'event_id' => null, 'is_builtin' => true,
            ]);
        }

        $event = Event::factory()->create();
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        $this->assertTrue($role->event->is($event));
        $this->assertFalse($role->is_builtin);
    }

    public function test_custom_role_names_are_case_insensitively_unique_per_event_and_cannot_collide_with_builtins(): void
    {
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        app(CreateCustomWeddingRole::class)->handle($first, ' Reader ');
        app(CreateCustomWeddingRole::class)->handle($second, 'reader');

        try {
            app(CreateCustomWeddingRole::class)->handle($first, ' READER ');
            $this->fail('A duplicate custom role should be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('wedding_roles', 15);
        }

        $this->expectException(DomainException::class);
        app(CreateCustomWeddingRole::class)->handle($first, 'maid OF honor');
    }

    public function test_builtin_roles_are_immutable(): void
    {
        $role = WeddingRole::query()->where('key', 'maid_of_honor')->firstOrFail();

        try {
            $role->update(['name' => 'Changed']);
            $this->fail('A built-in Wedding Role should not be editable.');
        } catch (DomainException) {
            $this->assertSame('Maid of Honor', $role->fresh()->name);
        }

        $this->expectException(DomainException::class);
        $role->delete();
    }

    public function test_guest_supports_multiple_roles_and_cross_event_custom_assignment_is_rejected_by_domain_and_database(): void
    {
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        $guest = app(CreateInvitation::class)->handle($first, [['first_name' => 'Ana']])->guests->first();
        $builtin = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        $local = app(CreateCustomWeddingRole::class)->handle($first, 'Reader');
        $foreign = app(CreateCustomWeddingRole::class)->handle($second, 'Usher');

        app(AssignWeddingRole::class)->handle($guest, $builtin);
        app(AssignWeddingRole::class)->handle($guest, $local);
        $this->assertCount(2, $guest->weddingRoles);

        try {
            app(AssignWeddingRole::class)->handle($guest, $foreign);
            $this->fail('Cross-Event role assignment should be rejected.');
        } catch (DomainException) {
            $this->assertCount(2, $guest->fresh()->weddingRoles);
        }

        $this->expectException(QueryException::class);
        DB::table('guest_wedding_role')->insert([
            'guest_id' => $guest->id, 'wedding_role_id' => $foreign->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_invitation_cascades_guests_and_assignments_and_custom_role_delete_keeps_guest(): void
    {
        $event = Event::factory()->create();
        $guest = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']])->guests->first();
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        app(AssignWeddingRole::class)->handle($guest, $role);

        $role->delete();
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertDatabaseMissing('guest_wedding_role', ['guest_id' => $guest->id]);

        $guest->invitation->delete();
        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_deleting_event_cascades_all_event_owned_invitation_records(): void
    {
        $event = Event::factory()->create();
        $guest = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']])->guests->first();
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        app(AssignWeddingRole::class)->handle($guest, $role);

        $event->delete();

        $this->assertDatabaseMissing('invitations', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('guests', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('wedding_roles', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('guest_wedding_role', ['guest_id' => $guest->id]);
        $this->assertDatabaseCount('wedding_roles', 13);
    }

    public function test_same_event_move_preserves_guest_identity_and_roles_while_cross_event_move_is_rejected(): void
    {
        $event = Event::factory()->create();
        $source = app(CreateInvitation::class)->handle($event, [['first_name' => 'Ana']]);
        $destination = app(CreateInvitation::class)->handle($event, [['first_name' => 'Pedro']]);
        $guest = $source->guests->first();
        $role = WeddingRole::query()->where('key', 'bridesmaid')->firstOrFail();
        app(AssignWeddingRole::class)->handle($guest, $role);

        $guest->invitation_id = $destination->id;
        $guest->save();
        $this->assertSame($destination->id, $guest->fresh()->invitation_id);
        $this->assertTrue($guest->fresh()->weddingRoles->contains($role));

        $foreign = app(CreateInvitation::class)->handle(Event::factory()->create(), [['first_name' => 'Other']]);
        $this->expectException(QueryException::class);
        $guest->invitation_id = $foreign->id;
        $guest->save();
    }
}
