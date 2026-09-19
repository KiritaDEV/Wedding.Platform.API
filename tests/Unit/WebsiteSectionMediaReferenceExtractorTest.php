<?php

namespace Tests\Unit;

use App\Website\WebsiteSectionCompositions;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebsiteSectionMediaReferenceExtractorTest extends TestCase
{
    private WebsiteSectionMediaReferenceExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new WebsiteSectionMediaReferenceExtractor(new WebsiteSectionCompositions);
    }

    #[DataProvider('sectionMediaCases')]
    public function test_extracts_active_section_media(string $type): void
    {
        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'sectionMedia', 'appearanceScope' => 'appearance/shared']],
        ], $this->extractor->extract('section', $type, ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]], ['shared' => ['backgroundMedia' => ['assetId' => 'media-one']]]));
    }

    public static function sectionMediaCases(): array
    {
        return [['hero']];
    }

    public function test_extracts_direct_and_nested_media_elements_but_not_direct_video_urls(): void
    {
        $content = $this->content([
            ['id' => 'direct', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'one', 'type' => 'image', 'mediaId' => 'media-one', 'alt' => 'One']]],
            ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
                ['id' => 'nested', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'two', 'type' => 'image', 'mediaId' => 'media-two', 'alt' => 'Two']]],
                ['id' => 'video', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'clip', 'type' => 'video', 'url' => 'https://example.com/video.mp4']]],
            ]],
        ]);

        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'sectionMedia', 'compositionScope' => 'shared', 'elementId' => 'direct', 'itemId' => 'one']],
            ['mediaId' => 'media-two', 'reference' => ['type' => 'sectionMedia', 'compositionScope' => 'shared', 'elementId' => 'nested', 'itemId' => 'two']],
        ], $this->extractor->extract('blank-section', 'blank', $content));
    }

    public function test_extracts_direct_and_nested_group_background_media(): void
    {
        $content = $this->content([[
            'id' => 'outer', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => 'group-one'], 'children' => [[
                'id' => 'inner', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => 'group-two'], 'children' => [],
            ]],
        ]]);
        $this->assertSame([
            ['mediaId' => 'group-one', 'reference' => ['type' => 'sectionMedia', 'compositionScope' => 'shared', 'elementId' => 'outer']],
            ['mediaId' => 'group-two', 'reference' => ['type' => 'sectionMedia', 'compositionScope' => 'shared', 'elementId' => 'inner']],
        ], $this->extractor->extract('blank', 'blank', $content));
    }

    public function test_extracts_people_block_media_from_blank_and_group(): void
    {
        $people = [
            'id' => 'people', 'type' => 'people', 'editorName' => 'People 1',
            'groups' => [[
                'id' => 'friends', 'name' => 'Friends',
                'people' => [['id' => 'alex', 'name' => 'Alex', 'media' => ['assetId' => 'media-one']]],
            ]],
        ];
        $content = $this->content([['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$people]]]);
        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'person', 'compositionScope' => 'shared', 'elementId' => 'people', 'personId' => 'alex', 'label' => 'Alex', 'groupId' => 'friends', 'groupLabel' => 'Friends']],
        ], $this->extractor->extract('blank', 'blank', $content));
    }

    public function test_enumerates_only_persisted_compositions_and_retains_occurrence_context(): void
    {
        $media = fn (string $scope, bool $hidden = false): array => [
            'id' => $scope.'-media', 'type' => 'media', 'editorName' => 'Media 1', 'isHidden' => $hidden,
            'items' => [['id' => $scope.'-item', 'type' => 'image', 'mediaId' => 'same-asset', 'alt' => $scope]],
        ];
        $content = $this->content([$media('shared', true)]);
        $content['compositions']['custom'] = [
            'desktop' => ['childFlow' => ['elements' => [$media('desktop')], 'order' => []]],
            'mobile' => ['childFlow' => ['elements' => [$media('mobile')], 'order' => []]],
        ];

        $references = $this->extractor->extract('section', 'blank', $content);
        $this->assertSame(['shared', 'custom/desktop', 'custom/mobile'], array_column(array_column($references, 'reference'), 'compositionScope'));
        $this->assertSame(['shared-media', 'desktop-media', 'mobile-media'], array_column(array_column($references, 'reference'), 'elementId'));
        $this->assertCount(3, $references);
    }

    public function test_scans_custom_only_nested_group_background_people_and_media(): void
    {
        $content = $this->content([]);
        $content['compositions']['custom']['tablet'] = ['childFlow' => ['elements' => [[
            'id' => 'outer', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => 'background'], 'children' => [[
                'id' => 'inner', 'type' => 'compositionGroup', 'children' => [
                    ['id' => 'media', 'type' => 'media', 'items' => [['id' => 'image', 'type' => 'image', 'mediaId' => 'image']]],
                    ['id' => 'people', 'type' => 'people', 'groups' => [['id' => 'family', 'people' => [['id' => 'person', 'media' => ['assetId' => 'person-photo']]]]]],
                ],
            ]],
        ]], 'order' => []]];

        $references = $this->extractor->extract('section', 'blank', $content);
        $this->assertEqualsCanonicalizing(['background', 'image', 'person-photo'], array_column($references, 'mediaId'));
        $this->assertSame(['custom/tablet'], array_values(array_unique(array_column(array_column($references, 'reference'), 'compositionScope'))));
    }

    #[DataProvider('emptyAndMalformedCases')]
    public function test_skips_null_absent_and_malformed_references(string $type, array $content): void
    {
        $this->assertSame([], $this->extractor->extract('section', $type, $content));
    }

    public static function emptyAndMalformedCases(): array
    {
        return [
            ['hero', []],
            ['gallery', ['semantic' => ['items' => [['id' => 'missing-media']]]]],
        ];
    }

    public function test_extracts_gallery_items_with_stable_item_context(): void
    {
        $content = ['semantic' => ['items' => [
            ['id' => 'first', 'type' => 'image', 'mediaId' => 'same'],
            ['id' => 'second', 'type' => 'image', 'mediaId' => 'same'],
        ]]];
        $this->assertSame([
            ['mediaId' => 'same', 'reference' => ['type' => 'sectionMedia', 'itemId' => 'first']],
            ['mediaId' => 'same', 'reference' => ['type' => 'sectionMedia', 'itemId' => 'second']],
        ], $this->extractor->extract('gallery', 'gallery', $content));
    }

    private function content(array $elements): array
    {
        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => $elements, 'order' => []]]]];
    }
}
