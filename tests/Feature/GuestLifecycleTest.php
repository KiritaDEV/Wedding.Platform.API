<?php

namespace Tests\Feature;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\UpdateInvitation;
use App\Enums\GuestStatus;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_is_active_by_default_and_omission_is_not_deletion(): void
    {
        $invitation = app(CreateInvitation::class)->handle(Event::factory()->create(), [
            ['first_name' => 'Active'], ['first_name' => 'Preserved'],
        ]);
        $active = $invitation->guests->first();
        $preserved = $invitation->guests->last();
        $updated = app(UpdateInvitation::class)->handle($invitation, [[
            'id' => $active->id, 'first_name' => 'Active', 'status' => 'active',
        ]], null);

        $this->assertSame(GuestStatus::Active, $active->refresh()->status);
        $this->assertNotNull($preserved->fresh());
        $this->assertCount(2, $updated->guests);
    }

    public function test_last_active_guest_cannot_be_deactivated_or_deleted(): void
    {
        $invitation = app(CreateInvitation::class)->handle(Event::factory()->create(), [['first_name' => 'Only']]);
        $guest = $invitation->guests->sole();

        foreach ([[[['id' => $guest->id, 'first_name' => 'Only', 'status' => 'inactive']], []], [[], [$guest->id]]] as [$guests, $deleted]) {
            try {
                app(UpdateInvitation::class)->handle($invitation, $guests, null, [], $deleted);
                $this->fail('Expected the active-Guest invariant to reject the mutation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('guests', $exception->errors());
            }
        }
    }

    public function test_inactive_guests_are_excluded_from_name_and_count_but_remain_in_detail(): void
    {
        $invitation = app(CreateInvitation::class)->handle(Event::factory()->create(), [
            ['first_name' => 'Maria'], ['first_name' => 'Hidden'],
        ]);
        $hidden = $invitation->guests->last();
        $hidden->update(['status' => GuestStatus::Inactive]);
        $invitation = $invitation->fresh('guests');

        $this->assertSame('Maria', $invitation->effectiveName());
        $this->assertCount(2, $invitation->guests);
        $this->assertCount(1, $invitation->activeGuests);
    }
}
