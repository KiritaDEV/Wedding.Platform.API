<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'publishedWebsiteId' => $this->published_website_id,
            'eventDate' => $this->event_date?->toDateString(),
            'startTime' => $this->start_time === null ? null : substr($this->start_time, 0, 5),
            'timeZone' => $this->time_zone,
            'rsvpIsOpen' => $this->rsvp_is_open,
            'rsvpDeadline' => $this->rsvp_deadline?->toDateString(),
            'rsvpIsEffectivelyOpen' => $this->isRsvpEffectivelyOpen(),
            'startsAtUtc' => $this->startsAtUtc()?->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->status->value,
            'membershipRole' => $this->membershipRole($request),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    private function membershipRole(Request $request): ?string
    {
        if ($this->relationLoaded('memberships')) {
            return $this->memberships->first()?->role->value;
        }

        return $this->pivot?->role;
    }
}
