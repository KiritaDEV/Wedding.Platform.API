<?php

namespace App\Models;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use App\Enums\GuestStatus;
use App\Enums\RsvpResponse;
use App\Invitations\NameNormalizer;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['event_id', 'first_name', 'last_name', 'relationship', 'side', 'status', 'rsvp_response'];

    protected function casts(): array
    {
        return [
            'relationship' => GuestRelationship::class,
            'side' => GuestSide::class,
            'status' => GuestStatus::class,
            'rsvp_response' => RsvpResponse::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Guest $guest): void {
            $guest->first_name = NameNormalizer::display($guest->first_name);
            $guest->last_name = NameNormalizer::nullableDisplay($guest->last_name);
            $guest->normalized_first_name = NameNormalizer::identity($guest->first_name);
            $guest->normalized_last_name = NameNormalizer::nullableIdentity($guest->last_name);
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function weddingRoles(): BelongsToMany
    {
        return $this->belongsToMany(WeddingRole::class)->withTimestamps();
    }

    public function rsvpSubmissionItems(): HasMany
    {
        return $this->hasMany(RsvpSubmissionItem::class);
    }

    public function canPermanentlyDelete(): bool
    {
        return $this->rsvp_response === null && ! ($this->rsvp_submission_items_exists ?? $this->rsvpSubmissionItems()->exists());
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->last_name]));
    }
}
