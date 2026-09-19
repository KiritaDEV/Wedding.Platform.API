<?php

namespace Tests\Unit;

use App\Website\Elements\WebsiteElementIdentityRegenerator;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResponsiveSectionCompositionTest extends TestCase
{
    public function test_blank_accepts_complete_sibling_custom_compositions(): void
    {
        $content = $this->content(['shared', 'desktop', 'tablet', 'mobile']);
        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('blank', $content, ['text']));
    }

    public function test_unknown_device_sparse_branch_and_cross_composition_identity_are_rejected(): void
    {
        foreach ([
            ['semantic' => [], 'compositions' => ['shared' => $this->composition('shared'), 'custom' => ['watch' => $this->composition('watch')]]],
            ['semantic' => [], 'compositions' => ['shared' => $this->composition('shared'), 'custom' => ['mobile' => []]]],
            ['semantic' => [], 'compositions' => ['shared' => $this->composition('same'), 'custom' => ['mobile' => $this->composition('same')]]],
        ] as $content) {
            try {
                app(WebsiteSectionContentValidator::class)->validate('blank', $content, ['text']);
                $this->fail('Invalid composition content was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_regeneration_copies_all_branches_and_owned_people_identities_but_preserves_media_ids(): void
    {
        $people = [
            'id' => 'people', 'type' => 'people', 'editorName' => 'People 1',
            'groups' => [[
                'id' => 'group', 'name' => 'Family',
                'people' => [['id' => 'person', 'name' => 'Alex', 'media' => ['assetId' => '01K00000000000000000000000']]],
            ]],
        ];
        $content = ['semantic' => ['items' => [['id' => 'gallery-item', 'type' => 'image', 'mediaId' => '01K00000000000000000000000']]], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$people], 'order' => [['kind' => 'element', 'id' => 'people']]]], 'custom' => ['mobile' => ['childFlow' => ['elements' => [['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'item', 'type' => 'image', 'mediaId' => '01K00000000000000000000000', 'alt' => 'Photo']]]], 'order' => [['kind' => 'element', 'id' => 'media']]]]]]];
        $copy = app(WebsiteElementIdentityRegenerator::class)->regenerateSectionContent($content);
        $this->assertNotSame('people', $copy['compositions']['shared']['childFlow']['elements'][0]['id']);
        $this->assertNotSame('group', $copy['compositions']['shared']['childFlow']['elements'][0]['groups'][0]['id']);
        $this->assertNotSame('person', $copy['compositions']['shared']['childFlow']['elements'][0]['groups'][0]['people'][0]['id']);
        $this->assertNotSame('item', $copy['compositions']['custom']['mobile']['childFlow']['elements'][0]['items'][0]['id']);
        $this->assertNotSame('gallery-item', $copy['semantic']['items'][0]['id']);
        $this->assertSame('01K00000000000000000000000', $copy['semantic']['items'][0]['mediaId']);
        $this->assertSame('01K00000000000000000000000', $copy['compositions']['custom']['mobile']['childFlow']['elements'][0]['items'][0]['mediaId']);
    }

    public function test_media_extractor_scans_appearance_shared_and_custom_content(): void
    {
        $content = $this->content(['shared', 'mobile']);
        $appearance = ['shared' => ['backgroundMedia' => ['assetId' => 'hero-media']]];
        $content['compositions']['custom']['mobile']['childFlow']['elements'][0] = ['id' => 'mobile', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'item', 'type' => 'image', 'mediaId' => 'mobile-media', 'alt' => 'Mobile']]];
        $references = app(WebsiteSectionMediaReferenceExtractor::class)->extract('hero', 'hero', $content, $appearance);
        $this->assertEqualsCanonicalizing(['hero-media', 'mobile-media'], array_column($references, 'mediaId'));
    }

    private function content(array $branches): array
    {
        $content = ['semantic' => [], 'compositions' => ['shared' => $this->composition('shared')]];
        foreach (array_diff($branches, ['shared']) as $branch) {
            $content['compositions']['custom'][$branch] = $this->composition($branch);
        }

        return $content;
    }

    private function composition(string $id): array
    {
        return ['childFlow' => ['elements' => [['id' => $id, 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $id]]]]]]], 'order' => [['kind' => 'element', 'id' => $id]]]];
    }
}
