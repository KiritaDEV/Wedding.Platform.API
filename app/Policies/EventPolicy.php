<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return $user->isSuperAdmin()
            || $event->memberships()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }
}
