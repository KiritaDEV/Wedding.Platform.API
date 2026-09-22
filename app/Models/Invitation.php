<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Invitations\InvitationName;
use App\Invitations\NameNormalizer;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['custom_name', 'status'];

    protected function casts(): array
    {
        return ['status' => InvitationStatus::class];
    }

    protected static function booted(): void
    {
        static::saving(function (Invitation $invitation): void {
            $invitation->custom_name = NameNormalizer::nullableDisplay($invitation->custom_name);
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class)->orderBy('created_at')->orderBy('id');
    }

    public function effectiveName(): string
    {
        return InvitationName::resolve($this);
    }
}
