<?php

namespace App\Website\Capabilities;

final readonly class TemplateCapabilities
{
    /**
     * @param  list<string>  $elements
     * @param  list<ElementCapability>  $elementCapabilities
     * @param  list<SectionCapability>  $sections
     */
    public function __construct(
        public string $templateKey,
        public GlobalDesignCapability $globalDesign,
        public TemplateDesignLibrary $designLibrary,
        public ProjectDefaultsCapability $projectDefaults,
        public ProjectColorLibraryCapability $projectColorLibrary,
        public array $elements,
        public array $elementCapabilities,
        public array $sections,
    ) {}
}
