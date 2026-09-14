<?php

namespace App\Website;

use App\Enums\EventType;

final class WebsiteSectionRegistry
{
    /** @return array<string, WebsiteSectionDefinition> */
    public function all(): array
    {
        return [
            'hero' => $this->definition('hero', 'Hero', 10, ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]]),
            'gallery' => $this->definition('gallery', 'Gallery', 70, ['semantic' => ['heading' => '', 'items' => []]]),
            'rsvp' => $this->definition('rsvp', 'RSVP', 90, ['semantic' => ['heading' => '', 'description' => '', 'buttonLabel' => '']]),
            'blank' => $this->definition('blank', 'Section', 100, ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]], WebsiteSectionLifecycle::UserOwnedRepeatable),
        ];
    }

    public function get(string $key): ?WebsiteSectionDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, WebsiteSectionDefinition> */
    public function forEventType(EventType $eventType): array
    {
        return array_filter(
            $this->all(),
            fn (WebsiteSectionDefinition $definition): bool => $definition->supports($eventType),
        );
    }

    public function supports(EventType $eventType, string $sectionType): bool
    {
        return $this->get($sectionType)?->supports($eventType) ?? false;
    }

    /** @return array<string, WebsiteSectionDefinition> */
    public function defaultCompositionFor(EventType $eventType): array
    {
        return array_filter(
            $this->forEventType($eventType),
            fn (WebsiteSectionDefinition $definition): bool => $definition->lifecycle->isRequired(),
        );
    }

    /** @param array<string, mixed> $defaultContent */
    private function definition(string $key, string $displayName, int $defaultOrder, array $defaultContent, WebsiteSectionLifecycle $lifecycle = WebsiteSectionLifecycle::RequiredSingleton): WebsiteSectionDefinition
    {
        return new WebsiteSectionDefinition(
            key: $key,
            displayName: $displayName,
            supportedEventTypes: [EventType::Wedding],
            defaultEnabled: true,
            defaultOrder: $defaultOrder,
            defaultContent: $defaultContent,
            lifecycle: $lifecycle,
        );
    }
}
