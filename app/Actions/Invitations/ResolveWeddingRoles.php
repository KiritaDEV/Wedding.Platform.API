<?php

namespace App\Actions\Invitations;

use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\WeddingRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class ResolveWeddingRoles
{
    /**
     * @param  list<array{client_key: string, name: string}>  $drafts
     * @param  list<string>  $referencedKeys
     * @return array<string, WeddingRole>
     */
    public function handle(Event $event, array $drafts, array $referencedKeys): array
    {
        $referenced = array_flip($referencedKeys);
        $resolved = [];

        foreach ($drafts as $draft) {
            if (! isset($referenced[$draft['client_key']])) {
                continue;
            }

            $normalized = NameNormalizer::identity($draft['name']);
            $role = WeddingRole::query()
                ->where('normalized_name', $normalized)
                ->where(fn ($query) => $query->whereNull('event_id')->orWhere('event_id', $event->id))
                ->orderByRaw('event_id IS NULL DESC')
                ->lockForUpdate()
                ->first();

            if ($role === null) {
                try {
                    $role = app(CreateCustomWeddingRole::class)->handle($event, $draft['name']);
                } catch (UniqueConstraintViolationException) {
                    $role = $event->customWeddingRoles()->where('normalized_name', $normalized)->firstOrFail();
                }
            }

            $resolved[$draft['client_key']] = $role;
        }

        if (count($resolved) !== count($referenced)) {
            throw ValidationException::withMessages([
                'guests' => 'A Guest references an undefined draft Wedding Role.',
            ]);
        }

        return $resolved;
    }

    /** @param list<string> $ids */
    public function existing(Event $event, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $roles = WeddingRole::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where(fn ($query) => $query->whereNull('event_id')->orWhere('event_id', $event->id))
            ->get()
            ->keyBy('id');

        if ($roles->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'guests' => 'One or more Wedding Roles are unavailable for this Event.',
            ]);
        }

        return $roles->all();
    }
}
