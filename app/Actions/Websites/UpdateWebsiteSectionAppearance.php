<?php

namespace App\Actions\Websites;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\BackgroundMedia;
use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\SectionCapability;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\GalleryItemAnimation;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteAnimation;
use App\Website\WebsiteSectionAppearance;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionAppearance
{
    private const ROOT_OPTION_CONTROLS = ['mediaPlacement', 'mediaSize', 'cornerStyle', 'shadowStyle', 'foregroundColor', 'mediaContentGap'];

    public function __construct(private readonly WebsiteCapabilityResolver $capabilities) {}

    /** @param array<string, mixed> $appearance */
    public function handle(WebsiteSection $section, array $appearance, bool $persist = true): WebsiteSection
    {
        if (in_array($section->type, ['hero', 'gallery', 'blank'], true) && ! array_key_exists('shared', $appearance)) {
            throw ValidationException::withMessages(['appearance' => 'Composition Sections require the canonical shared/custom appearance envelope.']);
        }
        if (in_array($section->type, ['hero', 'gallery', 'blank'], true) && array_key_exists('shared', $appearance)) {
            if (array_diff(array_keys($appearance), ['shared', 'custom', 'designDefaults']) !== [] || ! is_array($appearance['shared']) || (isset($appearance['custom']) && ! is_array($appearance['custom']))) {
                throw ValidationException::withMessages(['appearance' => 'Provide the canonical shared/custom Section appearance envelope.']);
            }
            $contentCustom = array_keys($section->content['compositions']['custom'] ?? []);
            $appearanceCustom = array_keys($appearance['custom'] ?? []);
            sort($contentCustom);
            sort($appearanceCustom);
            if (array_diff($contentCustom, $appearanceCustom) !== [] || array_diff($appearanceCustom, ['desktop', 'tablet', 'mobile']) !== []) {
                throw ValidationException::withMessages(['appearance.custom' => 'Custom composition and appearance branches must be paired.']);
            }
            foreach (array_diff($appearanceCustom, $contentCustom) as $viewport) {
                if (! array_key_exists('animation', $appearance['custom'][$viewport]) && ! array_key_exists('galleryItemAnimation', $appearance['custom'][$viewport])
                    || $this->withoutAnimation($appearance['custom'][$viewport]) !== $this->withoutAnimation($appearance['shared'])) {
                    throw ValidationException::withMessages(["appearance.custom.{$viewport}" => 'An appearance-only device branch may differ from shared appearance only by animation.']);
                }
            }
            $normalized = ['shared' => $this->normalizeBranch($section, $appearance['shared'])];
            foreach ($appearance['custom'] ?? [] as $viewport => $branch) {
                if (! is_array($branch)) {
                    throw ValidationException::withMessages(["appearance.custom.{$viewport}" => 'Custom appearance must be a complete object.']);
                }
                $normalized['custom'][$viewport] = $this->normalizeBranch($section, $branch, true);
            }
            $assetIds = collect([$normalized['shared'], ...array_values($normalized['custom'] ?? [])])
                ->flatMap(fn (array $branch): array => BackgroundMedia::assetIds($branch['backgroundMedia'] ?? null))->unique()->values();
            if ($assetIds->isNotEmpty() && MediaAsset::query()->where('event_id', $section->website->event_id)->whereKey($assetIds)
                ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->count() !== $assetIds->count()) {
                throw ValidationException::withMessages(['appearance.backgroundMedia.assetId' => 'Select valid images from this Event Media Library.']);
            }
            if (isset($appearance['designDefaults'])) {
                $normalized['designDefaults'] = $appearance['designDefaults'];
            }
            $section->appearance = $normalized;
            if ($persist) {
                $section->save();
            }

            return $section;
        }

        return $this->applyBranch($section, $appearance, $persist);
    }

    private function normalizeBranch(WebsiteSection $section, array $appearance, bool $preserveExplicitAnimationNone = false): array
    {
        $copy = clone $section;
        $copy->appearance = $appearance;
        $this->applyBranch($copy, $appearance, false, $preserveExplicitAnimationNone);

        return $copy->appearance;
    }

    private function applyBranch(WebsiteSection $section, array $appearance, bool $persist, bool $preserveExplicitAnimationNone = false): WebsiteSection
    {
        $storedDesignDefaults = $section->appearance['designDefaults'] ?? null;
        $section->loadMissing('website');
        $templateKey = $section->website->template_key;
        $sectionCapability = $this->capabilities->section($templateKey, $section->type);
        if ($sectionCapability === null) {
            throw ValidationException::withMessages(['appearance' => 'Section appearance is not supported by the assigned Template.']);
        }

        $expectedKeys = $section->type === 'rsvp'
            ? []
            : ['headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis'];
        if (array_key_exists('animation', $appearance)) {
            $normalizedAnimation = WebsiteAnimation::normalize($appearance['animation'], 'appearance.animation', $preserveExplicitAnimationNone);
            if ($normalizedAnimation === null) {
                unset($appearance['animation']);
            } else {
                $appearance['animation'] = $normalizedAnimation;
                $expectedKeys[] = 'animation';
            }
        }
        if (array_key_exists('galleryItemAnimation', $appearance)) {
            if ($section->type !== 'gallery') {
                throw ValidationException::withMessages(['appearance.galleryItemAnimation' => 'Gallery item animation is supported only by Gallery Sections.']);
            }
            $normalizedGalleryAnimation = GalleryItemAnimation::normalize($appearance['galleryItemAnimation'], 'appearance.galleryItemAnimation', $preserveExplicitAnimationNone);
            if ($normalizedGalleryAnimation === null) {
                unset($appearance['galleryItemAnimation']);
            } else {
                $appearance['galleryItemAnimation'] = $normalizedGalleryAnimation;
                $expectedKeys[] = 'galleryItemAnimation';
            }
        }
        if (array_key_exists('specialized', $appearance)) {
            if ($section->type !== 'rsvp' || ! is_array($appearance['specialized']) || array_diff(array_keys($appearance['specialized']), ['content']) !== []
                || ! is_array($appearance['specialized']['content'] ?? null) || array_diff(array_keys($appearance['specialized']['content']), ['animation']) !== []) {
                throw ValidationException::withMessages(['appearance.specialized' => 'Specialized appearance is supported only for the RSVP content unit.']);
            }
            $normalizedAnimation = array_key_exists('animation', $appearance['specialized']['content'])
                ? WebsiteAnimation::normalize($appearance['specialized']['content']['animation'], 'appearance.specialized.content.animation')
                : null;
            if ($normalizedAnimation === null) {
                unset($appearance['specialized']);
            } else {
                $appearance['specialized'] = ['content' => ['animation' => $normalizedAnimation]];
                $expectedKeys[] = 'specialized';
            }
        }
        if ($section->type === 'gallery') {
            foreach (['columns', 'gap', 'aspectRatio', 'radius', 'shadow'] as $setting) {
                if (! array_key_exists($setting, $appearance)) {
                    continue;
                }
                $valid = match ($setting) {
                    'columns' => is_int($appearance[$setting]) && $appearance[$setting] >= 1 && $appearance[$setting] <= 6,
                    'gap' => in_array($appearance[$setting], ['small', 'medium', 'large'], true),
                    'aspectRatio' => in_array($appearance[$setting], ['square', 'portrait', 'landscape'], true),
                    'radius' => in_array($appearance[$setting], ['square', 'soft', 'rounded', 'pill'], true),
                    'shadow' => in_array($appearance[$setting], ['none', 'soft', 'medium', 'strong'], true),
                };
                if (! $valid) {
                    throw ValidationException::withMessages(["appearance.{$setting}" => "The selected Gallery {$setting} is invalid."]);
                }
                $expectedKeys[] = $setting;
            }
            if (array_key_exists('galleryContentInnerSpacing', $appearance)) {
                if (! is_array($appearance['galleryContentInnerSpacing'])) {
                    throw ValidationException::withMessages(['appearance.galleryContentInnerSpacing' => 'Gallery content inner spacing must use the shared four-sided spacing contract.']);
                }
                $appearance['galleryContentInnerSpacing'] = $this->normalizeInnerSpacing($appearance['galleryContentInnerSpacing'], 'appearance.galleryContentInnerSpacing');
                if ($appearance['galleryContentInnerSpacing'] === []) {
                    unset($appearance['galleryContentInnerSpacing']);
                } else {
                    $expectedKeys[] = 'galleryContentInnerSpacing';
                }
            }
        }
        $actualKeys = array_keys($appearance);
        $activePresentation = $sectionCapability->defaultPresentation;
        if (array_key_exists('presentation', $appearance)) {
            if (! is_string($appearance['presentation']) || $this->capabilities->presentation($templateKey, $section->type, $appearance['presentation']) === null) {
                throw ValidationException::withMessages(['appearance.presentation' => 'The selected presentation is invalid for this Section.']);
            }
            $activePresentation = $appearance['presentation'];
            $expectedKeys[] = 'presentation';
        }

        $desktopControls = $this->controlsById($templateKey, $section->type, $activePresentation, 'desktop');
        foreach (self::ROOT_OPTION_CONTROLS as $setting) {
            if (! array_key_exists($setting, $appearance)) {
                continue;
            }
            if (! $this->validOption($desktopControls[$setting] ?? null, $appearance[$setting])) {
                throw ValidationException::withMessages(["appearance.{$setting}" => "The selected {$setting} is not supported by this presentation."]);
            }
            $expectedKeys[] = $setting;
        }

        if (array_key_exists('mediaSpacing', $appearance)) {
            $this->validateSpacing($desktopControls['mediaSpacing'] ?? null, $appearance['mediaSpacing'], 'appearance.mediaSpacing', false);
            $expectedKeys[] = 'mediaSpacing';
        }
        if (array_key_exists('overlayStrength', $appearance)) {
            $control = $desktopControls['overlayStrength'] ?? null;
            if ($control?->type !== AppearanceControlType::Number || ! is_numeric($appearance['overlayStrength'])
                || $appearance['overlayStrength'] < $control->minimum || $appearance['overlayStrength'] > $control->maximum) {
                throw ValidationException::withMessages(['appearance.overlayStrength' => 'The selected overlay strength is not supported by this presentation.']);
            }
            $appearance['overlayStrength'] = (float) $appearance['overlayStrength'];
            $expectedKeys[] = 'overlayStrength';
        }
        if (array_key_exists('responsive', $appearance)) {
            if ($section->type !== 'rsvp' || ! is_array($appearance['responsive']) || array_diff(array_keys($appearance['responsive']), ['tablet', 'mobile']) !== []) {
                throw ValidationException::withMessages(['appearance.responsive' => 'Device-specific authored properties require a supported exact-device appearance owner.']);
            }
            foreach ($appearance['responsive'] as $viewport => &$override) {
                if (! is_array($override) || array_diff(array_keys($override), ['animation', 'specialized']) !== []) {
                    throw ValidationException::withMessages(["appearance.responsive.{$viewport}" => 'RSVP responsive appearance supports only animation ownership.']);
                }
                if (array_key_exists('animation', $override)) {
                    $normalized = WebsiteAnimation::normalize($override['animation'], "appearance.responsive.{$viewport}.animation", true);
                    if ($normalized === null) {
                        unset($override['animation']);
                    } else {
                        $override['animation'] = $normalized;
                    }
                }
                if (array_key_exists('specialized', $override)) {
                    if (! is_array($override['specialized']) || array_diff(array_keys($override['specialized']), ['content']) !== []
                        || ! is_array($override['specialized']['content'] ?? null) || array_diff(array_keys($override['specialized']['content']), ['animation']) !== []) {
                        throw ValidationException::withMessages(["appearance.responsive.{$viewport}.specialized" => 'RSVP specialized responsive appearance is invalid.']);
                    }
                    $normalized = array_key_exists('animation', $override['specialized']['content'])
                        ? WebsiteAnimation::normalize($override['specialized']['content']['animation'], "appearance.responsive.{$viewport}.specialized.content.animation", true)
                        : null;
                    if ($normalized === null) {
                        unset($override['specialized']);
                    } else {
                        $override['specialized'] = ['content' => ['animation' => $normalized]];
                    }
                }
            }
            unset($override);
            $appearance['responsive'] = array_filter($appearance['responsive'], fn (array $override): bool => $override !== []);
            if ($appearance['responsive'] === []) {
                unset($appearance['responsive']);
            } else {
                $expectedKeys[] = 'responsive';
            }
        }
        if (array_key_exists('decorativeAppearance', $appearance)) {
            if (isset($appearance['decorativeAppearance']['background']['customColor']) && is_string($appearance['decorativeAppearance']['background']['customColor'])) {
                $appearance['decorativeAppearance']['background']['customColor'] = strtoupper($appearance['decorativeAppearance']['background']['customColor']);
            }
            $this->validateSectionDecorativeAppearance($sectionCapability, $section->type, $appearance['decorativeAppearance'], $section->website->design_settings);
            $expectedKeys[] = 'decorativeAppearance';
        }
        if (array_key_exists('height', $appearance)) {
            $height = $appearance['height'];
            if ($section->type !== 'hero' || ! is_array($height) || count($height) !== 2 || array_diff(array_keys($height), ['unit', 'value']) !== []
                || ($height['unit'] ?? null) !== 'svh' || ! is_int($height['value'] ?? null)
                || $height['value'] < 25 || $height['value'] > 150) {
                throw ValidationException::withMessages(['appearance.height' => 'Hero height must use an svh integer between 25 and 150.']);
            }
            $expectedKeys[] = 'height';
        }
        if (array_key_exists('backgroundImageOpacity', $appearance)) {
            if ($section->type !== 'hero' || ! is_int($appearance['backgroundImageOpacity']) || $appearance['backgroundImageOpacity'] < 0 || $appearance['backgroundImageOpacity'] > 100) {
                throw ValidationException::withMessages(['appearance.backgroundImageOpacity' => 'Hero background image opacity must be an integer between 0 and 100.']);
            }
            if ($appearance['backgroundImageOpacity'] === 100) {
                unset($appearance['backgroundImageOpacity']);
            } else {
                $expectedKeys[] = 'backgroundImageOpacity';
            }
        }
        if (array_key_exists('backgroundMedia', $appearance)) {
            if ($section->type !== 'hero') {
                throw ValidationException::withMessages(['appearance.backgroundMedia' => 'Background media is supported only by Hero Sections.']);
            }
            BackgroundMedia::assertJsonNumbers($appearance['backgroundMedia'], 'appearance.backgroundMedia');
            Validator::make(['appearance' => $appearance], BackgroundMedia::rules('appearance.backgroundMedia'))->validate();
            if (is_array($appearance['backgroundMedia'])) {
                $appearance['backgroundMedia'] = BackgroundMedia::normalize($appearance['backgroundMedia']);
            }
            $expectedKeys[] = 'backgroundMedia';
        }
        if (array_key_exists('contentPosition', $appearance)) {
            if ($section->type !== 'hero' || ! $this->validHeroContentPosition($appearance['contentPosition'])) {
                throw ValidationException::withMessages(['appearance.contentPosition' => 'The selected Hero content position is invalid.']);
            }
            if ($appearance['contentPosition'] === 'center') {
                unset($appearance['contentPosition']);
            } else {
                $expectedKeys[] = 'contentPosition';
            }
        }
        if (array_key_exists('innerSpacing', $appearance)) {
            if (! in_array($section->type, ['hero', 'gallery', 'rsvp', 'blank'], true) || ! is_array($appearance['innerSpacing'])) {
                throw ValidationException::withMessages(['appearance.innerSpacing' => 'Section inner spacing must use the shared four-sided spacing contract.']);
            }
            $appearance['innerSpacing'] = $this->normalizeInnerSpacing($appearance['innerSpacing'], 'appearance.innerSpacing');
            if ($appearance['innerSpacing'] === []) {
                unset($appearance['innerSpacing']);
            } else {
                $expectedKeys[] = 'innerSpacing';
            }
        }

        $actualKeys = array_keys($appearance);

        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages(['appearance' => 'Provide the complete supported Section appearance.']);
        }

        foreach (['headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis'] as $setting) {
            if ($section->type === 'rsvp') {
                continue;
            }
            if (in_array($section->type, ['blank', 'hero'], true)) {
                $allowed = $setting === 'backgroundTreatment' ? ['inherit', 'custom'] : ['inherit'];
                if (! in_array($appearance[$setting] ?? null, $allowed, true)) {
                    throw ValidationException::withMessages(["appearance.{$setting}" => "This Section does not support authored {$setting} overrides."]);
                }

                continue;
            }
            if (! $this->validOption($desktopControls[$setting] ?? null, $appearance[$setting])) {
                throw ValidationException::withMessages(["appearance.{$setting}" => "The selected {$setting} is invalid for this Section."]);
            }
        }

        if (isset($appearance['responsive'])) {
            foreach ($appearance['responsive'] as $viewport => &$override) {
                $viewportControls = $this->controlsById($templateKey, $section->type, $activePresentation, $viewport);
                foreach ($override as $setting => $value) {
                    if ($setting === 'innerSpacing') {
                        $override[$setting] = array_filter($value, fn (string $spacing, string $side): bool => $spacing !== ($appearance['innerSpacing'][$side] ?? 'none'), ARRAY_FILTER_USE_BOTH);
                        if ($override[$setting] === []) {
                            unset($override[$setting]);
                        }
                    } elseif ($setting === 'contentPosition' && $value === ($appearance['contentPosition'] ?? 'center')) {
                        unset($override[$setting]);
                    } elseif ($value === ($viewportControls[$setting]->default ?? null)) {
                        unset($override[$setting]);
                    }
                }
            }
            unset($override);
            $appearance['responsive'] = array_filter($appearance['responsive'], fn (array $override): bool => $override !== []);
            if ($appearance['responsive'] === []) {
                unset($appearance['responsive']);
            }
        }

        if ($storedDesignDefaults !== null) {
            $appearance['designDefaults'] = is_array($storedDesignDefaults) && $storedDesignDefaults === []
                ? new \stdClass
                : $storedDesignDefaults;
        }
        $section->appearance = $appearance;
        if ($persist) {
            $section->save();
        }

        return $section;
    }

    /** @param array<string, mixed> $designSettings */
    private function validateSectionDecorativeAppearance(SectionCapability $capability, string $sectionType, mixed $value, array $designSettings): void
    {
        if (! in_array($sectionType, ['hero', 'gallery', 'rsvp', 'blank'], true) || $capability->decorativeAppearance === null || ! is_array($value)) {
            throw ValidationException::withMessages(['appearance.decorativeAppearance' => 'Decorative appearance is not supported by this Section.']);
        }
        $rootKeys = array_keys($value);
        sort($rootKeys);
        if (array_diff($rootKeys, ['background', 'frame']) !== []) {
            throw ValidationException::withMessages(['appearance.decorativeAppearance' => 'Decorative appearance contains unsupported properties.']);
        }
        if (array_key_exists('background', $value)) {
            if (! is_array($value['background']) || array_diff(array_keys($value['background']), ['texture', 'textureStrength', 'pattern', 'patternStrength', 'overlay', 'colorId', 'customColor']) !== []) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.background' => 'Decorative background contains unsupported properties.']);
            }
            foreach (['texture' => 'textures', 'pattern' => 'patterns', 'overlay' => 'overlays'] as $field => $allowed) {
                if (array_key_exists($field, $value['background']) && (! is_string($value['background'][$field]) || ! in_array($value['background'][$field], $capability->decorativeAppearance->{$allowed}, true))) {
                    throw ValidationException::withMessages(["appearance.decorativeAppearance.background.{$field}" => "The selected {$field} is not supported by this Template."]);
                }
            }
            if (array_key_exists('textureStrength', $value['background']) && (! is_int($value['background']['textureStrength']) || $value['background']['textureStrength'] < 10 || $value['background']['textureStrength'] > 100)) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.background.textureStrength' => 'Texture strength must be an integer between 10 and 100.']);
            }
            if (array_key_exists('patternStrength', $value['background']) && (! is_int($value['background']['patternStrength']) || $value['background']['patternStrength'] < 10 || $value['background']['patternStrength'] > 100)) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.background.patternStrength' => 'Pattern strength must be an integer between 10 and 100.']);
            }
            if (array_key_exists('customColor', $value['background']) && (! is_string($value['background']['customColor']) || preg_match('/^#[0-9A-F]{6}$/', $value['background']['customColor']) !== 1)) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.background.customColor' => 'Custom color must use #RRGGBB format.']);
            }
            if (array_key_exists('colorId', $value['background'])) {
                $projectColorIds = array_column((new ProjectColorLibrary)->normalize($designSettings['customColors'] ?? []), 'id');
                $allowedColorIds = [...$capability->decorativeAppearance->backgroundColorIds, ...$projectColorIds];
                if (! is_string($value['background']['colorId']) || ! in_array($value['background']['colorId'], $allowedColorIds, true)) {
                    throw ValidationException::withMessages(['appearance.decorativeAppearance.background.colorId' => 'The selected background color is not supported by this Website.']);
                }
            }
        }
        if (array_key_exists('frame', $value)) {
            if (! is_array($value['frame']) || array_diff(array_keys($value['frame']), ['style', 'size', 'strength', 'colorId']) !== []) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.frame' => 'Decorative frame contains unsupported properties.']);
            }
            if (array_key_exists('style', $value['frame']) && (! is_string($value['frame']['style']) || ! in_array($value['frame']['style'], $capability->decorativeAppearance->frames, true))) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.frame.style' => 'The selected frame is not supported by this Template.']);
            }
            if (array_key_exists('size', $value['frame']) && (! is_int($value['frame']['size']) || $value['frame']['size'] < 50 || $value['frame']['size'] > 200)) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.frame.size' => 'Frame size must be an integer between 50 and 200.']);
            }
            if (array_key_exists('strength', $value['frame']) && (! is_int($value['frame']['strength']) || $value['frame']['strength'] < 0 || $value['frame']['strength'] > 100)) {
                throw ValidationException::withMessages(['appearance.decorativeAppearance.frame.strength' => 'Frame strength must be an integer between 0 and 100.']);
            }
            if (array_key_exists('colorId', $value['frame'])) {
                $projectColorIds = array_column((new ProjectColorLibrary)->normalize($designSettings['customColors'] ?? []), 'id');
                $allowedColorIds = [...$capability->decorativeAppearance->frameColorIds, ...$projectColorIds];
                if (! is_string($value['frame']['colorId']) || ! in_array($value['frame']['colorId'], $allowedColorIds, true)) {
                    throw ValidationException::withMessages(['appearance.decorativeAppearance.frame.colorId' => 'The selected frame color is not supported by this Website.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $responsive */
    private function validateResponsiveOverrides(string $templateKey, string $sectionType, ?string $presentation, array $responsive): void
    {
        foreach ($responsive as $viewport => $override) {
            if (! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true) || ! is_array($override)) {
                throw ValidationException::withMessages(["appearance.responsive.{$viewport}" => 'The selected responsive viewport is invalid.']);
            }
            $controls = $this->controlsById($templateKey, $sectionType, $presentation, $viewport);
            foreach ($override as $setting => $value) {
                if (! in_array($setting, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true)) {
                    throw ValidationException::withMessages(["appearance.responsive.{$viewport}.{$setting}" => 'This responsive appearance property is not supported.']);
                }
                if ($setting === 'innerSpacing') {
                    if (! in_array($sectionType, ['hero', 'blank'], true) || ! is_array($value)) {
                        throw ValidationException::withMessages(["appearance.responsive.{$viewport}.innerSpacing" => 'Section inner spacing must use the shared spacing contract.']);
                    }
                    $this->normalizeInnerSpacing($value, "appearance.responsive.{$viewport}.innerSpacing");
                } elseif ($setting === 'contentPosition') {
                    if ($sectionType !== 'hero' || ! $this->validHeroContentPosition($value)) {
                        throw ValidationException::withMessages(["appearance.responsive.{$viewport}.contentPosition" => 'The selected Hero content position is invalid.']);
                    }
                } elseif ($setting === 'mediaSpacing') {
                    $this->validateSpacing($controls[$setting] ?? null, $value, "appearance.responsive.{$viewport}.mediaSpacing", true);
                } elseif (! $this->validOption($controls[$setting] ?? null, $value)) {
                    throw ValidationException::withMessages(["appearance.responsive.{$viewport}.{$setting}" => "The selected {$setting} is not supported for this viewport."]);
                }
            }
        }
    }

    private function validHeroContentPosition(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['top-start', 'top-center', 'top-end', 'center-start', 'center', 'center-end', 'bottom-start', 'bottom-center', 'bottom-end'], true);
    }

    /** @param array<string,mixed> $appearance @return array<string,mixed> */
    private function withoutAnimation(array $appearance): array
    {
        unset($appearance['animation']);
        unset($appearance['galleryItemAnimation']);

        return $appearance;
    }

    /** @param array<string, mixed> $value */
    private function normalizeInnerSpacing(array $value, string $path): array
    {
        if (array_diff(array_keys($value), ['top', 'right', 'bottom', 'left']) !== []) {
            throw ValidationException::withMessages([$path => 'Inner spacing contains unsupported sides.']);
        }
        foreach ($value as $side => $spacing) {
            if (! is_string($spacing) || ! in_array($spacing, ['none', 'xs', 's', 'm', 'l', 'xl'], true)) {
                throw ValidationException::withMessages(["{$path}.{$side}" => 'The selected inner spacing is invalid.']);
            }
            if ($spacing === 'none') {
                unset($value[$side]);
            }
        }

        return $value;
    }

    private function validateSpacing(?AppearanceControlCapability $control, mixed $value, string $path, bool $responsive): void
    {
        $sides = ['top', 'right', 'bottom', 'left'];
        $actualSides = is_array($value) ? array_keys($value) : [];
        sort($actualSides);
        $expectedSides = $sides;
        sort($expectedSides);
        if ($control?->type !== AppearanceControlType::Spacing || ! is_array($value) || $actualSides !== $expectedSides) {
            throw ValidationException::withMessages([$path => 'Provide all supported media spacing sides without extra properties.']);
        }

        $allowed = array_column($control->options, 'key');
        foreach ($sides as $side) {
            if (! is_string($value[$side]) || ! in_array($value[$side], $allowed, true)) {
                throw ValidationException::withMessages([
                    "{$path}.{$side}" => $responsive
                        ? "The selected {$side} media spacing is not supported for this viewport."
                        : "The selected {$side} media spacing is not supported by this presentation.",
                ]);
            }
        }
    }

    private function validOption(?AppearanceControlCapability $control, mixed $value): bool
    {
        return $control?->type === AppearanceControlType::Option && is_string($value)
            && in_array($value, array_column($control->options, 'key'), true);
    }

    /** @return array<string, AppearanceControlCapability> */
    private function controlsById(string $templateKey, string $sectionType, ?string $presentation, string $viewport): array
    {
        $controls = $this->capabilities->controlsForViewport($templateKey, $sectionType, $presentation, $viewport) ?? [];

        return collect($controls)->keyBy('id')->all();
    }
}
