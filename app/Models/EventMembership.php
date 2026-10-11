<?php

namespace App\Models;

use App\Enums\EventMembershipRole;
use Database\Factories\EventMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMembership extends Model
{
    /** @use HasFactory<EventMembershipFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => EventMembershipRole::class,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
