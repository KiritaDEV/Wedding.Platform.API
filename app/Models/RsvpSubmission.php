<?php

namespace App\Models;

use App\Enums\RsvpActorType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RsvpSubmission extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'invitation_id', 'actor_type', 'actor_user_id', 'actor_name_snapshot', 'note'];

    protected function casts(): array
    {
        return ['actor_type' => RsvpActorType::class];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RsvpSubmissionItem::class, 'submission_id')->orderBy('snapshot_order')->orderBy('id');
    }
}
