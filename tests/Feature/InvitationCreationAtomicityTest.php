<?php

namespace Tests\Feature;

use App\Actions\Invitations\ProvisionPrivateInvitationLink;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class InvitationCreationAtomicityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_application_creation_rolls_back_invitation_when_private_link_provisioning_fails(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        EventMembership::factory()->for($event)->for($owner)->create(['role' => EventMembershipRole::Owner]);
        $this->assertSame(0, DB::transactionLevel());
        $this->forceProvisioningFailure();

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/invitations", [
            'guests' => [['firstName' => 'Rollback Me']],
        ])->assertServerError();

        $this->assertDatabaseMissing('invitations', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('guests', ['event_id' => $event->id]);
        $this->assertDatabaseCount('invitation_private_links', 0);
    }

    public function test_supported_factory_creation_rolls_back_invitation_when_private_link_provisioning_fails(): void
    {
        $event = Event::factory()->create();
        $this->assertSame(0, DB::transactionLevel());
        $this->forceProvisioningFailure();

        try {
            Invitation::factory()->for($event)->create();
            $this->fail('Factory creation should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced private-link provisioning failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invitations', ['event_id' => $event->id]);
        $this->assertDatabaseCount('invitation_private_links', 0);
    }

    private function forceProvisioningFailure(): void
    {
        $this->app->instance(ProvisionPrivateInvitationLink::class, new class
        {
            public function handle(Invitation $invitation): never
            {
                throw new RuntimeException('Forced private-link provisioning failure.');
            }
        });
    }
}
