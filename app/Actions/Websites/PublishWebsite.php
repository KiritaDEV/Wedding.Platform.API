<?php

namespace App\Actions\Websites;

use App\Models\Event;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublishWebsite
{
    public function handle(Event $event, Website $website): Website
    {
        if ($website->event_id !== $event->id) {
            throw ValidationException::withMessages(['website' => 'The Website Project must belong to this Event.']);
        }

        DB::transaction(function () use ($event, $website): void {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            if ($locked->published_website_id !== $website->id) {
                $locked->update(['published_website_id' => $website->id]);
            }
        });

        return $website->refresh();
    }
}
