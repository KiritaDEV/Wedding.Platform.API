<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationPrivateLink extends Model
{
    use HasUlids;

    protected $guarded = ['token_hash', 'encrypted_token', 'current_slot'];

    protected $hidden = ['token_hash', 'encrypted_token'];

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function path(): string
    {
        return '/i/'.$this->encrypted_token;
    }
}
