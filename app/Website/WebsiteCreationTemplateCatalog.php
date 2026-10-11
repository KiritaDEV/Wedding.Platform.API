<?php

namespace App\Website;

use App\Enums\EventType;

final class WebsiteCreationTemplateCatalog
{
    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionRegistry $sections,
    ) {}

    /** @return array<string, WebsiteTemplateDefinition> */
    public function forEventType(EventType $eventType): array
    {
        return array_filter(
            $this->templates->forEventType($eventType),
            fn (WebsiteTemplateDefinition $template): bool => $this->unsupportedSectionTypes($template, $eventType) === [],
        );
    }

    /** @return list<string> */
    public function unsupportedSectionTypes(WebsiteTemplateDefinition $template, EventType $eventType): array
    {
        return array_values(array_filter(
            array_keys($this->sections->defaultCompositionFor($eventType)),
            fn (string $sectionType): bool => ! $template->supportsSection($sectionType),
        ));
    }
}
