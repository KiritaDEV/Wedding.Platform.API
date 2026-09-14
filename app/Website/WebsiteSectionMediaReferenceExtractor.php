<?php

namespace App\Website;

final class WebsiteSectionMediaReferenceExtractor
{
    public function __construct(private readonly WebsiteSectionCompositions $compositions) {}

    /** @param array<string, mixed> $content */
    public function extract(string $sectionId, string $sectionType, array $content, array $appearance = []): array
    {
        $references = match ($sectionType) {
            'hero' => $this->sectionMedia($appearance),
            default => [],
        };
        foreach ($this->compositions->persisted($content) as $branch) {
            $this->appendElementMedia($references, $branch['composition']['childFlow']['elements'] ?? [], $branch['scope']);
        }

        return $references;
    }

    /** @param array<int, mixed> $elements */
    private function appendElementMedia(array &$references, mixed $elements, string $compositionScope): void
    {
        if (! is_array($elements)) {
            return;
        }
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if (($element['type'] ?? null) === 'media') {
                foreach (is_array($element['items'] ?? null) ? $element['items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (is_string($item['mediaId'] ?? null)) {
                        $references[] = ['mediaId' => $item['mediaId'], 'reference' => array_filter([
                            'type' => 'sectionMedia',
                            'compositionScope' => $compositionScope,
                            'elementId' => is_string($element['id'] ?? null) ? $element['id'] : '',
                            'itemId' => is_string($item['id'] ?? null) ? $item['id'] : '',
                        ], fn (string $value): bool => $value !== '')];
                    }
                }
            }
            if (($element['type'] ?? null) === 'people') {
                array_push($references, ...$this->people($element, $compositionScope));
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                foreach (BackgroundMedia::assetIds($element['backgroundMedia'] ?? null) as $backgroundMediaId) {
                    $references[] = ['mediaId' => $backgroundMediaId, 'reference' => array_filter([
                        'type' => 'sectionMedia', 'compositionScope' => $compositionScope,
                        'elementId' => is_string($element['id'] ?? null) ? $element['id'] : '',
                    ], fn (string $value): bool => $value !== '')];
                }
                $this->appendElementMedia($references, $element['children'] ?? [], $compositionScope);
            }
        }
    }

    private function sectionMedia(array $appearance): array
    {
        $references = [];
        foreach (['shared' => $appearance['shared'] ?? null, ...($appearance['custom'] ?? [])] as $scope => $branch) {
            foreach (BackgroundMedia::assetIds(is_array($branch) ? ($branch['backgroundMedia'] ?? null) : null) as $mediaId) {
                $references[] = ['mediaId' => $mediaId, 'reference' => ['type' => 'sectionMedia', 'appearanceScope' => 'appearance/'.$scope]];
            }
        }

        return $references;
    }

    private function people(array $content, string $compositionScope): array
    {
        $references = [];
        foreach (is_array($content['groups'] ?? null) ? $content['groups'] : [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach (is_array($group['people'] ?? null) ? $group['people'] : [] as $person) {
                if (! is_array($person) || ! is_string($person['media']['assetId'] ?? null) || ! is_string($person['id'] ?? null)) {
                    continue;
                }
                $reference = ['type' => 'person', 'compositionScope' => $compositionScope, 'elementId' => $content['id'] ?? '', 'personId' => $person['id']];
                if (is_string($person['name'] ?? null) && trim($person['name']) !== '') {
                    $reference['label'] = $person['name'];
                }
                if (is_string($group['id'] ?? null)) {
                    $reference['groupId'] = $group['id'];
                }
                if (is_string($group['name'] ?? null) && trim($group['name']) !== '') {
                    $reference['groupLabel'] = $group['name'];
                }
                $references[] = ['mediaId' => $person['media']['assetId'], 'reference' => $reference];
            }
        }

        return $references;
    }
}
