<?php

namespace App\Models;

use App\Enums\RsvpResponse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RsvpSubmissionItem extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['guest_id', 'guest_name_snapshot', 'rsvp_response', 'snapshot_order'];

    protected function casts(): array
    {
        return ['rsvp_response' => RsvpResponse::class];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(RsvpSubmission::class, 'submission_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
