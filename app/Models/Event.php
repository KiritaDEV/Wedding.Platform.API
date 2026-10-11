<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::deleting(fn (Event $event) => $event->rsvpSubmissions()->delete());
    }

    protected $fillable = [
        'type',
        'name',
        'slug',
        'event_date',
        'start_time',
        'time_zone',
        'rsvp_is_open',
        'rsvp_deadline',
        'status',
        'published_website_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'event_date' => 'date',
            'rsvp_is_open' => 'boolean',
            'rsvp_deadline' => 'date',
            'status' => EventStatus::class,
        ];
    }

    public function hasRsvpDeadlineExpired(?CarbonImmutable $at = null): bool
    {
        if ($this->rsvp_deadline === null) {
            return false;
        }

        $at ??= CarbonImmutable::now('UTC');

        return $at->setTimezone(new DateTimeZone($this->time_zone))->toDateString() > $this->rsvp_deadline->toDateString();
    }

    public function isRsvpEffectivelyOpen(?CarbonImmutable $at = null): bool
    {
        return $this->rsvp_is_open && ! $this->hasRsvpDeadlineExpired($at);
    }

    public function startsAtUtc(): ?CarbonImmutable
    {
        if ($this->event_date === null || $this->start_time === null || $this->time_zone === null) {
            return null;
        }

        $local = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $this->event_date->toDateString().' '.substr($this->start_time, 0, 5),
            new DateTimeZone($this->time_zone),
        );

        return $local === false ? null : $local->utc();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EventMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_memberships')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function website(): HasOne
    {
        return $this->hasOne(Website::class);
    }

    public function websiteProjects(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function publishedWebsite(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'published_website_id');
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function customWeddingRoles(): HasMany
    {
        return $this->hasMany(WeddingRole::class);
    }

    public function rsvpSubmissions(): HasMany
    {
        return $this->hasMany(RsvpSubmission::class);
    }
}
