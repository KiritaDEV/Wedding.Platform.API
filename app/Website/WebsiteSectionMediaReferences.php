<?php

namespace App\Website;

final class WebsiteSectionMediaReferences
{
    public function __construct(private readonly WebsiteSectionMediaReferenceExtractor $extractor) {}

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{assetId: string, context?: array<string, string>}>
     */
    public function extract(string $sectionType, array $content, array $appearance = []): array
    {
        return array_map(function (array $item): array {
            $reference = $item['reference'];
            $context = match ($reference['type']) {
                'person' => array_filter([
                    'compositionScope' => $reference['compositionScope'] ?? '',
                    'elementId' => $reference['elementId'] ?? '',
                    'groupId' => $reference['groupId'] ?? '',
                    'groupName' => $reference['groupLabel'] ?? '',
                    'personId' => $reference['personId'],
                    'personName' => $reference['label'] ?? '',
                ], fn (string $value): bool => $value !== ''),
                default => array_filter([
                    'appearanceScope' => $reference['appearanceScope'] ?? '',
                    'compositionScope' => $reference['compositionScope'] ?? '',
                    'elementId' => $reference['elementId'] ?? '',
                    'itemId' => $reference['itemId'] ?? '',
                ], fn (string $value): bool => $value !== ''),
            };

            return array_filter([
                'assetId' => $item['mediaId'],
                'context' => $context === [] ? null : $context,
            ], fn (mixed $value): bool => $value !== null);
        }, $this->extractor->extract('', $sectionType, $content, $appearance));
    }
}
