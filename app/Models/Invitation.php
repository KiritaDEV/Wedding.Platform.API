<?php

namespace App\Models;

use App\Enums\GuestStatus;
use App\Enums\InvitationStatus;
use App\Enums\RsvpResponse;
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

    public function activeGuests(): HasMany
    {
        return $this->guests()->where('status', GuestStatus::Active->value);
    }

    public function rsvpSubmissions(): HasMany
    {
        return $this->hasMany(RsvpSubmission::class);
    }

    public function rsvpSummary(): array
    {
        $active = $this->guests->where('status', GuestStatus::Active);
        $attending = $active->where('rsvp_response', RsvpResponse::Attending)->count();
        $declined = $active->where('rsvp_response', RsvpResponse::Declined)->count();
        $pending = $active->count() - $attending - $declined;

        return [
            'status' => $pending === $active->count() ? 'pending' : ($pending > 0 ? 'partial' : 'complete'),
            'attendingCount' => $attending,
            'declinedCount' => $declined,
            'pendingCount' => $pending,
        ];
    }

    public function canPermanentlyDelete(): bool
    {
        $ownHistory = $this->rsvp_submissions_exists ?? $this->rsvpSubmissions()->exists();
        if ($ownHistory) {
            return false;
        }

        return ! $this->guests->contains(fn (Guest $guest): bool => ! $guest->canPermanentlyDelete());
    }

    public function effectiveName(): string
    {
        return InvitationName::resolve($this);
    }
}
