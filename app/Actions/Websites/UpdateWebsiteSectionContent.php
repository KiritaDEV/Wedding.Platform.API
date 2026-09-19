<?php

namespace App\Actions\Websites;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\Capabilities\ElementColorRole;
use App\Website\Capabilities\TypographyRole;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\DividerJsonShape;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionContent
{
    public function __construct(
        private readonly WebsiteSectionContentValidator $validator,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionMediaReferences $mediaReferences,
        private readonly WebsiteCapabilityResolver $capabilities,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content, bool $enforceAppearancePairing = true): WebsiteSection
    {
        $website = $section->website()->firstOrFail();
        $sectionCapability = $this->capabilities->section($website->template_key, $section->type);
        $textCapability = collect($this->capabilities->template($website->template_key)?->elementCapabilities)
            ->first(fn ($element): bool => $element->type->value === 'text')?->appearance;
        $allowedFontIds = collect($textCapability?->typography)->first(fn ($control): bool => $control->role === TypographyRole::Body)?->allowedFontIds;
        $allowedColorIds = collect($textCapability?->colors)->first(fn ($control): bool => $control->role === ElementColorRole::TextColor)?->allowedColorIds;
        $projectColorIds = array_column((new ProjectColorLibrary)->normalize($website->design_settings['customColors'] ?? []), 'id');
        $validated = $this->validator->validate(
            $section->type,
            $content,
            $sectionCapability?->allowedElementTypes,
            $allowedFontIds,
            $allowedColorIds === null ? null : [...$allowedColorIds, ...$projectColorIds],
            $website->template_key,
        );
        if ($enforceAppearancePairing && in_array($section->type, ['hero', 'gallery', 'blank'], true)) {
            $contentCustom = array_keys($validated['compositions']['custom'] ?? []);
            $appearanceCustom = array_keys($section->appearance['custom'] ?? []);
            sort($contentCustom);
            sort($appearanceCustom);
            if ($contentCustom !== $appearanceCustom) {
                throw ValidationException::withMessages(['content.compositions.custom' => 'Custom composition and appearance branches must be paired.']);
            }
        }
        $currentMedia = $section->content['media'] ?? null;
        $nextMedia = $validated['media'] ?? null;
        if ($currentMedia !== $nextMedia && $this->templates->get($website->template_key)?->mediaCapabilityFor($section->type) === null) {
            throw ValidationException::withMessages(['content.media' => 'This Template does not support Media for this Section.']);
        }
        if (is_array($nextMedia) && ! MediaAsset::query()->whereKey($nextMedia['assetId'])->where('event_id', $website->event_id)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
            throw ValidationException::withMessages(['content.media.assetId' => 'Select a valid image from this Event Media Library.']);
        }
        $nextItemReferences = $this->mediaReferences->extract($section->type, $validated, $section->appearance);
        if ($section->type === 'gallery') {
            foreach ($validated['semantic']['items'] as $index => $item) {
                if (! MediaAsset::query()->whereKey($item['mediaId'])->where('event_id', $website->event_id)
                    ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
                    $position = $index + 1;
                    throw ValidationException::withMessages([
                        "content.semantic.items.{$index}.mediaId" => "Gallery image {$position} is unavailable. Replace or delete it before saving.",
                    ]);
                }
            }
        }
        $assetIds = collect($nextItemReferences)->pluck('assetId')->unique()->values();
        if ($assetIds->isNotEmpty() && MediaAsset::query()->where('event_id', $website->event_id)->whereKey($assetIds)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->count() !== $assetIds->count()) {
            throw ValidationException::withMessages(['content.groups' => 'Select valid images from this Event Media Library.']);
        }
        if (isset($validated['compositions']['shared']['childFlow']['elements'])) {
            $validated['compositions']['shared']['childFlow']['elements'] = DividerJsonShape::serializeElements($validated['compositions']['shared']['childFlow']['elements']);
            foreach ($validated['compositions']['custom'] ?? [] as $viewport => $composition) {
                $validated['compositions']['custom'][$viewport]['childFlow']['elements'] = DividerJsonShape::serializeElements($composition['childFlow']['elements']);
            }
        }
        $section->content = $validated;
        $section->save();

        return $section;
    }
}
