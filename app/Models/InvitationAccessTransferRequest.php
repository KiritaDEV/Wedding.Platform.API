<?php

namespace App\Models;

use App\Enums\InvitationAccessTransferStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationAccessTransferRequest extends Model
{
    use HasUlids;

    protected $guarded = ['current_slot'];

    protected function casts(): array
    {
        return [
            'status' => InvitationAccessTransferStatus::class,
            'requested_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function requestedCredential(): BelongsTo
    {
        return $this->belongsTo(InvitationBrowserCredential::class, 'requested_credential_id');
    }
}
