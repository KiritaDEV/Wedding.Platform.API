<?php

namespace Tests\Unit;

use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\WebsiteElementIdentityRegenerator;
use App\Website\Elements\WebsiteElementValidator;
use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SwatchesContractTest extends TestCase
{
    private function element(): array
    {
        return ['id' => 'swatches', 'type' => 'swatches', 'editorName' => 'Swatches 1', 'items' => [['id' => 'a', 'color' => '#D1D5DB'], ['id' => 'b', 'color' => '#9CA3AF'], ['id' => 'c', 'color' => '#4B5563']]];
    }

    private function rejected(array $element): void
    {
        try {
            app(WebsiteElementValidator::class)->validate($element);
            $this->fail('Invalid Swatches accepted: '.json_encode($element));
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_surfaces_are_optional_and_preserved_by_validation_and_copy(): void
    {
        $legacy = [...$this->element(), 'appearance' => ['shape' => 'roundedSquare', 'size' => '3xl']];
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        foreach (['flat', 'raised', 'inset'] as $surface) {
            $element = [...$legacy, 'appearance' => [...$legacy['appearance'], 'surfaceStyle' => $surface]];
            $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
            $copy = app(WebsiteElementIdentityRegenerator::class)->regenerate([$element])[0];
            $this->assertSame($element['appearance'], $copy['appearance']);
            $this->assertNotSame($element['items'][0]['id'], $copy['items'][0]['id']);
        }
    }

    public function test_legacy_shape_is_never_rewritten_and_geometry_survives_copy(): void
    {
        $legacy = [...$this->element(), 'appearance' => ['shape' => 'roundedSquare']];
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        $legacy['appearance']['size'] = '3xl';
        $legacy['appearance']['captionStyle'] = 'plain';
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        foreach (['circle', 'square', 'rectangle', 'roundedSquare'] as $shape) {
            $element = [...$legacy, 'appearance' => [...$legacy['appearance'], 'shape' => $shape, 'cornerRadius' => 'full']];
            $copy = app(WebsiteElementIdentityRegenerator::class)->regenerate([$element])[0];
            $this->assertSame($element['appearance'], app(WebsiteElementValidator::class)->validate($copy)['appearance']);
        }
    }

    public function test_caption_colors_normalize_without_injecting_legacy_style_and_survive_copy(): void
    {
        $legacy = [...$this->element(), 'appearance' => ['showLabels' => true, 'showHex' => true]];
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        foreach (['plain', 'soft'] as $style) {
            $element = [...$legacy, 'appearance' => [...$legacy['appearance'], 'shape' => 'rectangle', 'captionStyle' => $style, 'captionTextColor' => '#aabbcc', 'captionBackgroundColor' => '#ddeeff']];
            $normalized = app(WebsiteElementValidator::class)->validate($element);
            $this->assertSame('#AABBCC', $normalized['appearance']['captionTextColor']);
            $this->assertSame('#DDEEFF', $normalized['appearance']['captionBackgroundColor']);
            $copy = app(WebsiteElementIdentityRegenerator::class)->regenerate([$normalized])[0];
            $this->assertSame($normalized['appearance'], $copy['appearance']);
            $this->assertNotSame($normalized['items'][0]['id'], $copy['items'][0]['id']);
        }
    }

    public function test_mirrored_web_contract_and_normalization(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/swatches-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['validColors'] as $color) {
            $element = $this->element();
            $element['items'] = [['id' => ' a ', 'color' => $color, 'label' => ' Slate '], ['id' => 'b', 'color' => $color]];
            $normalized = app(WebsiteElementValidator::class)->validate($element);
            $this->assertSame(['id' => 'a', 'color' => strtoupper($color), 'label' => 'Slate'], $normalized['items'][0]);
            $this->assertSame(strtoupper($color), $normalized['items'][1]['color']);
        }
        foreach ($fixture['invalidColors'] as $color) {
            $this->rejected([...$this->element(), 'items' => [['id' => 'a', 'color' => $color]]]);
        }
        foreach ($fixture['validAppearance'] as $appearance) {
            $element = [...$this->element(), 'appearance' => $appearance];
            $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        }
        // [] cannot distinguish {} here; raw request/snapshot tests cover that boundary.
        foreach (array_slice($fixture['invalidAppearance'], 1) as $appearance) {
            if ($appearance === ['captionTypography' => []]) {
                continue;
            }
            $this->rejected([...$this->element(), 'appearance' => $appearance]);
        }
    }

    public function test_bounds_identifiers_labels_and_copy(): void
    {
        foreach ([[], array_fill(0, 13, ['id' => 'a', 'color' => '#000000']), [['id' => 'a', 'color' => '#000000'], ['id' => ' a ', 'color' => '#FFFFFF']], [['id' => 'swatches', 'color' => '#000000']], [['id' => ' ', 'color' => '#000000']]] as $items) {
            $this->rejected([...$this->element(), 'items' => $items]);
        }
        foreach ([null, '<b>Red</b>', str_repeat('😀', 81)] as $label) {
            $this->rejected([...$this->element(), 'items' => [['id' => 'a', 'color' => '#000000', 'label' => $label]]]);
        }
        $element = [...$this->element(), 'items' => [['id' => 'a', 'color' => '#000000', 'label' => str_repeat('😀', 80)]]];
        $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        $element['items'][0]['label'] = ' ';
        $this->assertArrayNotHasKey('label', app(WebsiteElementValidator::class)->validate($element)['items'][0]);
        $element['items'] = array_map(fn ($i) => ['id' => 'item-'.$i, 'color' => '#FFFFFF'], range(1, 12));
        $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        $copy = app(WebsiteElementIdentityRegenerator::class)->regenerate([$element])[0];
        $this->assertNotSame($element['id'], $copy['id']);
        $this->assertEmpty(array_intersect(array_column($element['items'], 'id'), array_column($copy['items'], 'id')));
        $this->assertSame(array_column($element['items'], 'color'), array_column($copy['items'], 'color'));
    }

    public function test_capabilities_nested_groups_and_cross_owner_ids(): void
    {
        foreach (['classic-filipiniana-v1', 'modern-editorial-v1'] as $template) {
            foreach (['hero', 'blank'] as $type) {
                $this->assertTrue(app(WebsiteCapabilityResolver::class)->allowsElement($template, $type, 'swatches'));
                foreach ([0, 1, 2] as $depth) {
                    $element = $this->element();
                    for ($i = 0; $i < $depth; $i++) {
                        $element = ['id' => 'g'.$i, 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$element]];
                    }
                    $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$element], 'order' => [['kind' => 'element', 'id' => $element['id']]]]]]];
                    $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate($type, $content, templateKey: $template));
                }
            }
            foreach (['gallery', 'rsvp', 'people', 'schedule'] as $type) {
                $this->assertFalse(app(WebsiteCapabilityResolver::class)->allowsElement($template, $type, 'swatches'));
            }
        }
        $tooDeep = ['id' => 'g3', 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$element]];
        $this->rejected($tooDeep);
        $collision = $this->element();
        $collision['id'] = 'another';
        $this->expectException(ValidationException::class);
        app(WebsiteElementValidator::class)->validateTree([$this->element(), $collision]);
    }
}
