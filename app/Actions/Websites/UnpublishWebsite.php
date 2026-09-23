<?php

namespace App\Actions\Websites;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

final class UnpublishWebsite
{
    public function handle(Event $event): Event
    {
        DB::transaction(function () use ($event): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->update(['published_website_id' => null]);
        });

        return $event->refresh();
    }
}
