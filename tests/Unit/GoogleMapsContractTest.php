<?php

namespace Tests\Unit;

use App\Website\Elements\GoogleMaps;
use App\Website\Elements\WebsiteElementValidator;
use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleMapsContractTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/google-maps-contract.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_url_parity_and_canonical_storage(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture['valid'] as $url) {
            $this->assertTrue(GoogleMaps::validUrl($url), $url);
            $element = ['id' => 'map', 'type' => 'map', 'editorName' => 'Map', 'embedUrl' => $url];
            $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        }
        foreach ($fixture['invalid'] as $url) {
            $this->assertFalse(GoogleMaps::validUrl($url), $url);
        }
    }

    public function test_generic_compositions_and_two_group_levels(): void
    {
        foreach (['blank', 'hero'] as $type) {
            foreach ([0, 1, 2] as $depth) {
                $map = ['id' => 'map', 'type' => 'map', 'editorName' => 'Map', 'embedUrl' => $this->fixture()['url'], 'appearance' => ['height' => 'large', 'alignment' => 'end', 'cornerRadius' => 'small', 'outerSpacing' => ['top' => 'm']]];
                for ($i = 0; $i < $depth; $i++) {
                    $map = ['id' => 'group-'.$i, 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$map]];
                }
                $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$map], 'order' => [['kind' => 'element', 'id' => $map['id']]]]]]];
                $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate($type, $content, templateKey: 'modern-editorial-v1'));
            }
        }
    }

    public function test_unsafe_data_is_rejected(): void
    {
        foreach ([['embedUrl' => 'http://www.google.com/maps/embed?pb=!1m2!x'], ['html' => $this->fixture()['iframe']], ['srcdoc' => 'bad'], ['appearance' => ['height' => 4]], ['appearance' => ['cornerRadius' => 'huge']], ['isHidden' => 'true']] as $extra) {
            try {
                app(WebsiteElementValidator::class)->validate(array_merge(['id' => 'map', 'type' => 'map', 'editorName' => 'Map'], $extra));
                $this->fail('Invalid map accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_third_group_level_is_rejected(): void
    {
        $map = ['id' => 'map', 'type' => 'map', 'editorName' => 'Map'];
        for ($i = 0; $i < 3; $i++) {
            $map = ['id' => 'g'.$i, 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$map]];
        }
        $this->expectException(ValidationException::class);
        app(WebsiteElementValidator::class)->validate($map);
    }

    public function test_width_presets_and_legacy_maps_round_trip_without_injected_defaults(): void
    {
        $legacy = ['id' => 'map', 'type' => 'map', 'editorName' => 'Map'];
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        foreach (['small', 'medium', 'large', 'full'] as $width) {
            $map = [...$legacy, 'appearance' => ['width' => $width, 'height' => 'large', 'cornerRadius' => 'small', 'alignment' => 'end']];
            $this->assertSame($map, app(WebsiteElementValidator::class)->validate($map));
        }
        foreach (['wide', '320px', '100%', '', 320, null, false, []] as $width) {
            try {
                app(WebsiteElementValidator::class)->validate([...$legacy, 'appearance' => ['width' => $width]]);
                $this->fail('Invalid width accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_shadow_presets_and_invalid_values(): void
    {
        $legacy = ['id' => 'map', 'type' => 'map', 'editorName' => 'Map'];
        $this->assertSame($legacy, app(WebsiteElementValidator::class)->validate($legacy));
        foreach (['none', 'soft', 'medium', 'strong'] as $shadow) {
            $map = [...$legacy, 'appearance' => ['shadow' => $shadow]];
            $this->assertSame($map, app(WebsiteElementValidator::class)->validate($map));
        }
        foreach (['subtle', 'heavy', '', null, false, 1, []] as $shadow) {
            try {
                app(WebsiteElementValidator::class)->validate([...$legacy, 'appearance' => ['shadow' => $shadow]]);
                $this->fail('Invalid Map shadow accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
