<?php

namespace App\Actions\Invitations;

use App\Invitations\BuiltinWeddingRoles;
use App\Invitations\NameNormalizer;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SynchronizeBuiltinWeddingRoles
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            foreach (BuiltinWeddingRoles::all() as $key => $definition) {
                $normalizedName = NameNormalizer::identity($definition['name']);
                $existing = DB::table('wedding_roles')
                    ->where('key', $key)
                    ->orWhere('id', $definition['id'])
                    ->first();

                if ($existing !== null) {
                    if ($existing->id !== $definition['id']
                        || $existing->key !== $key
                        || $existing->event_id !== null
                        || $existing->scope_key !== 'builtin'
                        || $existing->name !== $definition['name']
                        || $existing->normalized_name !== $normalizedName
                        || ! (bool) $existing->is_builtin) {
                        throw new DomainException("Built-in Wedding Role [{$key}] does not match its canonical definition.");
                    }

                    continue;
                }

                if (DB::table('wedding_roles')
                    ->whereNotNull('event_id')
                    ->where('normalized_name', $normalizedName)
                    ->exists()) {
                    throw new DomainException("Built-in Wedding Role [{$key}] conflicts with an existing Event custom role.");
                }

                $now = now();
                DB::table('wedding_roles')->insert([
                    'id' => $definition['id'],
                    'event_id' => null,
                    'scope_key' => 'builtin',
                    'key' => $key,
                    'name' => $definition['name'],
                    'normalized_name' => $normalizedName,
                    'is_builtin' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
