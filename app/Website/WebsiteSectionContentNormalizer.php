<?php

namespace App\Website;

use DomainException;

final class WebsiteSectionContentNormalizer
{
    public function __construct(private readonly WebsiteSectionRegistry $sections) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function normalize(string $sectionId, string $sectionType, array $content): array
    {
        $definition = $this->sections->get($sectionType);
        if ($definition === null) {
            throw new DomainException("Website section type [{$sectionType}] has no runtime content adapter.");
        }

        // Development Websites created before RSVP composition existed have no
        // canonical child flow. Return the one current default rather than
        // exposing obsolete semantic fields or making the whole builder unloadable.
        if ($sectionType === 'rsvp' && ! is_array($content['compositions'] ?? null)) {
            return $definition->defaultContent;
        }

        return match ($sectionType) {
            'hero', 'gallery', 'rsvp', 'blank' => $content,
            default => throw new DomainException("Website section type [{$sectionType}] has no runtime content adapter."),
        };
    }
}
