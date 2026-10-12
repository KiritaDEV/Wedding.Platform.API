<?php

namespace App\Actions\Events;

use App\Enums\EventMembershipRole;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveEventCollaborator
{
    public function handle(Event $event, string $membershipId): void
    {
        DB::transaction(function () use ($event, $membershipId) {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            Gate::authorize('manageCollaborators', $locked);
            $membership = $locked->memberships()->whereKey($membershipId)->firstOrFail();
            if ($membership->role === EventMembershipRole::Owner) {
                throw ValidationException::withMessages(['membership' => 'The Event Owner cannot be removed.']);
            }
            $membership->delete();
        }, 3);
    }
}
