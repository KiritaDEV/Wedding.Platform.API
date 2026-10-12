<?php

namespace App\Actions\Events;

use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AddEventCollaborator
{
    public function handle(Event $event, string $email): EventMembership
    {
        return DB::transaction(function () use ($event, $email) {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            Gate::authorize('manageCollaborators', $locked);
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                throw ValidationException::withMessages(['email' => 'This account is unavailable for collaboration.']);
            }
            if ($locked->memberships()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This user is already an Event member.']);
            }

            return $locked->memberships()->create([
                'user_id' => $user->id,
                'role' => EventMembershipRole::Admin,
            ])->load('user');
        }, 3);
    }
}
