<?php

namespace App\Actions\Events;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

final class UpdateEventTiming
{
    /** @param array{event_date: ?string, start_time: ?string, time_zone: ?string} $attributes */
    public function handle(Event $event, array $attributes): Event
    {
        return DB::transaction(function () use ($event, $attributes): Event {
            $event->fill($attributes)->save();

            return $event->refresh();
        });
    }
}
