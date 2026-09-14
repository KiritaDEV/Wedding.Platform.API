<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\Capabilities\ContextDefaultsIntent;
use App\Website\Capabilities\DesignContextResolver;
use App\Website\Capabilities\ResolvedDesignContext;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateWebsiteSectionDesignDefaults
{
    private const KEYS = [
        'headingFontId',
        'bodyFontId',
        'headingColorId',
        'bodyColorId',
        'accentColorId',
    ];

    public function __construct(
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly DesignContextResolver $contexts,
    ) {}

    /** @param array<string, mixed> $designDefaults */
    public function handle(WebsiteSection $section, array $designDefaults): WebsiteSection
    {
        if ($section->type === 'blank') {
            throw ValidationException::withMessages(['designDefaults' => 'Blank Section Design Defaults are not supported.']);
        }
        $section->loadMissing('website');
        $website = $section->website;
        $sectionCapability = $this->capabilities->section($website->template_key, $section->type);
        $projectDefaults = $this->capabilities->resolveProjectDesignDefaults($website->template_key, $website->design_settings);
        if ($sectionCapability === null || $projectDefaults === null) {
            throw ValidationException::withMessages(['designDefaults' => 'Section Design Defaults are not supported.']);
        }

        if (array_diff(array_keys($designDefaults), self::KEYS) !== []) {
            throw ValidationException::withMessages(['designDefaults' => 'Section Design Defaults contain unsupported properties.']);
        }
        if (array_filter($designDefaults, fn (mixed $value): bool => ! is_string($value)) !== []) {
            throw ValidationException::withMessages(['designDefaults' => 'Section Design Default values must be strings.']);
        }

        try {
            $intent = ContextDefaultsIntent::fromArray($designDefaults);
            $parent = ResolvedDesignContext::fromProjectDefaults($projectDefaults);
            $this->contexts->resolveSection($parent, $sectionCapability, $intent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['designDefaults' => $exception->getMessage()]);
        }

        $storedAppearance = is_array($section->appearance) ? $section->appearance : [];
        $appearance = in_array($section->type, ['hero', 'blank'], true) ? ($storedAppearance['shared'] ?? []) : $storedAppearance;
        $existing = is_array($storedAppearance['designDefaults'] ?? null) ? $storedAppearance['designDefaults'] : [];
        $presentationId = is_string($appearance['presentation'] ?? null)
            ? $appearance['presentation']
            : $sectionCapability->defaultPresentation;
        $presentation = $presentationId === null
            ? null
            : $this->capabilities->presentation($website->template_key, $section->type, $presentationId);
        $effectiveCapability = $presentation?->contextDefaults ?? $sectionCapability->contextDefaults;
        $activeKeys = array_keys($this->contexts->allowedContextValues($effectiveCapability));
        foreach ($designDefaults as $key => $value) {
            if (! in_array($key, $activeKeys, true) && ($existing[$key] ?? null) !== $value) {
                throw ValidationException::withMessages([
                    "designDefaults.{$key}" => 'This Section Design Default is controlled by the active presentation.',
                ]);
            }
        }

        $storedAppearance['designDefaults'] = (object) $designDefaults;
        $section->appearance = $storedAppearance;
        $section->save();

        return $section;
    }
}
