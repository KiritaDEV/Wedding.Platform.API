<?php

namespace App\Actions\Websites;

use App\Models\Event;
use App\Website\Publishing\WebsitePublicationCoordinator;

final class UnpublishWebsite
{
    public function __construct(private readonly WebsitePublicationCoordinator $coordinator) {}

    public function handle(Event $event): Event
    {
        $this->coordinator->forEvent($event, function (Event $locked): void {
            $locked->published_website_id = null;
            if (! $locked->save()) {
                throw new \RuntimeException('Unpublication pointer update was cancelled.');
            }
        });

        return $event->refresh();
    }
}
