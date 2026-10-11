<?php

namespace App\Models;

use App\Enums\UserNotificationSource;
use App\Enums\UserNotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => UserNotificationType::class,
            'source_type' => UserNotificationSource::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
