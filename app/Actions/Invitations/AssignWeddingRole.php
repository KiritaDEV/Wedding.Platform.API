<?php

namespace App\Actions\Invitations;

use App\Models\Guest;
use App\Models\WeddingRole;
use DomainException;

final class AssignWeddingRole
{
    public function handle(Guest $guest, WeddingRole $role): void
    {
        if ($role->event_id !== null && $role->event_id !== $guest->event_id) {
            throw new DomainException('A custom Wedding Role must belong to the Guest Event.');
        }

        $guest->weddingRoles()->syncWithoutDetaching([$role->getKey()]);
        $guest->unsetRelation('weddingRoles');
    }
}
