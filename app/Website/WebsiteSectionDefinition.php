<?php

namespace App\Website;

use App\Enums\EventType;

final readonly class WebsiteSectionDefinition
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     * @param  array<string, mixed>  $defaultContent
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $supportedEventTypes,
        public bool $defaultEnabled,
        public int $defaultOrder,
        public array $defaultContent,
        public WebsiteSectionLifecycle $lifecycle,
    ) {}

    public function supports(EventType $eventType): bool
    {
        return in_array($eventType, $this->supportedEventTypes, true);
    }
}
