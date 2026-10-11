<?php

namespace App\Website;

use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;

final class WebsiteDraftNormalizer
{
    public function __construct(
        private readonly WebsiteSectionContentNormalizer $sectionContent,
        private readonly WebsiteCapabilityResolver $capabilities,
    ) {}

    /**
     * @return array{
     *   schemaVersion: int,
     *   id: string,
     *   eventId: string,
     *   name: string,
     *   templateKey: string,
     *   designSettings: array<string, mixed>,
     *   projectDesignDefaults: array{headingFontId: string, bodyFontId: string, headingColorId: string, bodyColorId: string, accentColorId: string}|null,
     *   sections: list<array{section: WebsiteSection, content: array<string, mixed>}>
     * }
     */
    public function normalize(Website $website): array
    {
        $sourceVersion = $website->schema_version;
        if ($sourceVersion < WebsiteSchema::LEGACY_SCHEMA_VERSION || $sourceVersion > WebsiteSchema::CURRENT_SCHEMA_VERSION) {
            throw new UnsupportedWebsiteSchemaVersion($sourceVersion, WebsiteSchema::CURRENT_SCHEMA_VERSION);
        }

        $website->loadMissing('sections.website');
        $storedDesignSettings = $website->design_settings;
        $designSettings = $this->capabilities->normalizeDesignSettings($website->template_key, $storedDesignSettings);
        $resolved = $this->capabilities->resolveProjectDesignDefaults(
            $website->template_key,
            is_array($storedDesignSettings) ? $storedDesignSettings : [],
        );

        return [
            'schemaVersion' => WebsiteSchema::CURRENT_SCHEMA_VERSION,
            'id' => $website->id,
            'eventId' => $website->event_id,
            'name' => $website->name,
            'templateKey' => $website->template_key,
            'designSettings' => $designSettings === null ? $storedDesignSettings : [
                ...$designSettings,
                'projectDefaults' => (object) $designSettings['projectDefaults'],
            ],
            'projectDesignDefaults' => $resolved === null ? null : [
                'headingFontId' => $resolved->headingFontId,
                'bodyFontId' => $resolved->bodyFontId,
                'headingColorId' => $resolved->headingColorId,
                'bodyColorId' => $resolved->bodyColorId,
                'accentColorId' => $resolved->accentColorId,
            ],
            'sections' => $website->sections->map(fn (WebsiteSection $section): array => [
                'section' => $section,
                'content' => $this->sectionContent->normalize($section->id, $section->type, $section->content),
            ])->values()->all(),
        ];
    }
}
