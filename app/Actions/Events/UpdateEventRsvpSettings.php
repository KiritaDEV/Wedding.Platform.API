<?php

namespace App\Actions\Events;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

final class UpdateEventRsvpSettings
{
    public function handle(Event $event, array $settings): Event
    {
        return DB::transaction(function () use ($event, $settings): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $locked->update($settings);

            return $locked->refresh();
        });
    }
}
