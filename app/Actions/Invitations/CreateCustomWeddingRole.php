<?php

namespace App\Actions\Invitations;

use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\WeddingRole;
use DomainException;

final class CreateCustomWeddingRole
{
    public function handle(Event $event, string $name): WeddingRole
    {
        $normalized = NameNormalizer::identity($name);
        if ($normalized === '') {
            throw new DomainException('A custom Wedding Role name is required.');
        }

        if (WeddingRole::query()->whereNull('event_id')->where('normalized_name', $normalized)->exists()) {
            throw new DomainException('A built-in Wedding Role already has this name.');
        }

        return $event->customWeddingRoles()->create(['name' => $name]);
    }
}
