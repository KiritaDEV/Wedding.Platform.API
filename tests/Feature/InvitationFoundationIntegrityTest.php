<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateCustomWeddingRole;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\SynchronizeBuiltinWeddingRoles;
use App\Invitations\BuiltinWeddingRoles;
use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\WeddingRole;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationFoundationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unicode_canonical_equivalents_share_identity_while_unaccented_text_remains_distinct(): void
    {
        $precomposed = 'José';
        $combining = "Jose\u{0301}";

        $this->assertSame(NameNormalizer::identity($precomposed), NameNormalizer::identity($combining));
        $this->assertNotSame(NameNormalizer::identity($precomposed), NameNormalizer::identity('Jose'));
    }

    public function test_canonically_equivalent_guest_names_cannot_coexist_in_one_event(): void
    {
        $event = Event::factory()->create();
        app(CreateInvitation::class)->handle($event, [['first_name' => 'José']]);

        $this->expectException(ValidationException::class);
        app(CreateInvitation::class)->handle($event, [['first_name' => "Jose\u{0301}"]]);
    }

    public function test_canonically_equivalent_custom_role_names_cannot_coexist_in_one_event(): void
    {
        $event = Event::factory()->create();
        app(CreateCustomWeddingRole::class)->handle($event, 'Lectór');

        $this->expectException(QueryException::class);
        app(CreateCustomWeddingRole::class)->handle($event, "Lecto\u{0301}r");
    }

    public function test_supported_model_paths_reject_invalid_builtin_and_custom_catalog_states(): void
    {
        $event = Event::factory()->create();

        foreach ([
            function () use ($event): void {
                $role = new WeddingRole(['name' => 'Invalid Built-in']);
                $role->event_id = $event->id;
                $role->is_builtin = true;
                $role->key = 'invalid_builtin';
                $role->save();
            },
            fn () => WeddingRole::query()->create(['name' => 'Ownerless Custom']),
            function () use ($event): void {
                $role = new WeddingRole(['name' => 'Keyed Custom']);
                $role->event_id = $event->id;
                $role->key = 'not_allowed';
                $role->save();
            },
        ] as $createInvalidRole) {
            try {
                $createInvalidRole();
                $this->fail('An invalid Wedding Role catalog state should be rejected.');
            } catch (DomainException) {
                $this->assertDatabaseMissing('wedding_roles', ['name' => 'Invalid Built-in']);
            }
        }
    }

    public function test_custom_scope_is_derived_from_immutable_event_ownership_and_builtin_collisions_are_rejected_on_update(): void
    {
        $event = Event::factory()->create();
        $role = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');

        $role->scope_key = 'incorrect';
        $role->save();
        $this->assertSame($event->id, $role->fresh()->scope_key);

        $this->expectException(DomainException::class);
        $role->update(['name' => 'Maid of Honor']);
    }

    public function test_builtin_catalog_synchronization_is_idempotent_additive_and_does_not_modify_custom_roles(): void
    {
        $event = Event::factory()->create();
        $custom = app(CreateCustomWeddingRole::class)->handle($event, 'Reader');
        $definition = BuiltinWeddingRoles::all()['flower_girl'];
        DB::table('wedding_roles')->where('id', $definition['id'])->delete();

        $synchronize = app(SynchronizeBuiltinWeddingRoles::class);
        $synchronize->handle();
        $synchronize->handle();

        $this->assertDatabaseHas('wedding_roles', [
            'id' => $definition['id'],
            'key' => 'flower_girl',
            'name' => $definition['name'],
            'event_id' => null,
            'is_builtin' => true,
        ]);
        $this->assertDatabaseHas('wedding_roles', [
            'id' => $custom->id,
            'event_id' => $event->id,
            'name' => 'Reader',
        ]);
        $this->assertDatabaseCount('wedding_roles', count(BuiltinWeddingRoles::all()) + 1);
    }

    public function test_catalog_synchronization_refuses_to_rewrite_a_changed_builtin(): void
    {
        $definition = BuiltinWeddingRoles::all()['best_man'];
        DB::table('wedding_roles')->where('id', $definition['id'])->update(['name' => 'Changed']);

        $this->expectException(DomainException::class);
        app(SynchronizeBuiltinWeddingRoles::class)->handle();
    }

    public function test_catalog_synchronization_refuses_to_create_an_effective_duplicate_of_a_custom_role(): void
    {
        $definition = BuiltinWeddingRoles::all()['flower_girl'];
        DB::table('wedding_roles')->where('id', $definition['id'])->delete();
        $event = Event::factory()->create();
        app(CreateCustomWeddingRole::class)->handle($event, 'Flower Girl');

        $this->expectException(DomainException::class);
        app(SynchronizeBuiltinWeddingRoles::class)->handle();
    }
}
