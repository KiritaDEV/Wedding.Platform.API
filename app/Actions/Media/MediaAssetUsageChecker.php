<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Support\Collection;

final class MediaAssetUsageChecker
{
    public function __construct(
        private readonly WebsiteSectionRegistry $sections,
        private readonly WebsiteSectionMediaReferenceExtractor $mediaReferences,
    ) {}

    public function isUsed(MediaAsset $asset): bool
    {
        return $this->resolve(collect([$asset]))[$asset->id]->isInUse();
    }

    public function usageFor(MediaAsset $asset): MediaAssetUsage
    {
        return $this->resolve(collect([$asset]))[$asset->id];
    }

    /** @param Collection<int, MediaAsset> $assets */
    public function attach(Collection $assets): void
    {
        $usage = $this->resolve($assets);
        $assets->each(fn (MediaAsset $asset) => $asset->setRelation('resolvedUsage', $usage[$asset->id]));
    }

    /**
     * @param  Collection<int, MediaAsset>  $assets
     * @return array<string, MediaAssetUsage>
     */
    public function resolve(Collection $assets): array
    {
        $usage = $assets->mapWithKeys(fn (MediaAsset $asset): array => [$asset->id => []])->all();
        if ($assets->isEmpty()) {
            return [];
        }

        $assetEvents = $assets->mapWithKeys(fn (MediaAsset $asset): array => [$asset->event_id.':'.$asset->id => $asset->id]);
        $websiteSections = WebsiteSection::query()
            ->join('websites', 'websites.id', '=', 'website_sections.website_id')
            ->whereIn('websites.event_id', $assets->pluck('event_id')->unique())
            ->orderBy('websites.id')
            ->orderBy('website_sections.sort_order')
            ->orderBy('website_sections.id')
            ->get([
                'website_sections.id', 'website_sections.type', 'website_sections.content', 'website_sections.appearance', 'websites.event_id',
                'websites.id as website_project_id', 'websites.name as website_project_name',
            ]);

        foreach ($websiteSections as $section) {
            foreach ($this->mediaReferences->extract($section->id, $section->type, $section->content, $section->appearance) as $extracted) {
                $resolvedAssetId = $assetEvents->get($section->event_id.':'.$extracted['mediaId']);
                if ($resolvedAssetId === null) {
                    continue;
                }

                $usage[$resolvedAssetId][] = [
                    'mediaId' => $extracted['mediaId'],
                    'eventId' => $section->event_id,
                    'websiteProjectId' => $section->website_project_id,
                    'websiteProjectName' => $section->website_project_name,
                    'sectionId' => $section->id,
                    'sectionType' => $section->type,
                    'sectionName' => $this->sections->get($section->type)?->displayName ?? $section->type,
                    'reference' => $extracted['reference'],
                ];
            }
        }

        return collect($usage)->map(fn (array $references): MediaAssetUsage => new MediaAssetUsage(
            collect($references)->unique(fn (array $reference): string => $this->identity($reference))->values()->all(),
        ))->all();
    }

    /** @param array<string, mixed> $record */
    private function identity(array $record): string
    {
        $reference = $record['reference'];

        return implode(':', array_map(fn (mixed $value): string => (string) $value, [
            $record['mediaId'], $record['websiteProjectId'], $record['sectionId'], $reference['type'],
            $reference['compositionScope'] ?? '', $reference['elementId'] ?? '', $reference['itemId'] ?? '',
            $reference['groupId'] ?? '', $reference['personId'] ?? '',
        ]));
    }
}
