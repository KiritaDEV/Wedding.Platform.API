<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvitationBrowserCredential extends Model
{
    use HasUlids;

    protected $guarded = ['secret_hash', 'current_slot'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(InvitationAccessTransferRequest::class, 'requested_credential_id');
    }
}
