<?php

namespace App\Models;

use App\Invitations\NameNormalizer;
use Database\Factories\WeddingRoleFactory;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WeddingRole extends Model
{
    /** @use HasFactory<WeddingRoleFactory> */
    use HasFactory, HasUlids;

    protected $attributes = ['is_builtin' => false];

    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (WeddingRole $role): void {
            if ($role->exists && $role->getOriginal('is_builtin')) {
                throw new DomainException('Built-in Wedding Roles are immutable.');
            }

            $role->name = NameNormalizer::display($role->name);
            $role->normalized_name = NameNormalizer::identity($role->name);

            if (! $role->exists && $role->is_builtin) {
                throw new DomainException('Built-in Wedding Roles must be created by the canonical catalog synchronizer.');
            }

            if ($role->event_id === null) {
                throw new DomainException('A custom Wedding Role must belong to an Event.');
            }

            if ($role->key !== null) {
                throw new DomainException('A custom Wedding Role cannot have a built-in machine key.');
            }

            if ($role->exists && ($role->event_id !== $role->getOriginal('event_id')
                || $role->is_builtin !== (bool) $role->getOriginal('is_builtin')
                || $role->key !== $role->getOriginal('key'))) {
                throw new DomainException('Wedding Role catalog identity cannot be changed.');
            }

            if (WeddingRole::query()
                ->whereNull('event_id')
                ->where('normalized_name', $role->normalized_name)
                ->exists()) {
                throw new DomainException('A built-in Wedding Role already has this name.');
            }

            $role->scope_key = $role->event_id;
        });

        static::deleting(function (WeddingRole $role): void {
            if ($role->is_builtin) {
                throw new DomainException('Built-in Wedding Roles cannot be deleted.');
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class)->withTimestamps();
    }
}
