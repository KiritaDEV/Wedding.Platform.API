<?php

namespace Tests\Unit;

use App\Website\Elements\ScheduleAppearance;
use App\Website\Elements\WebsiteElementValidator;
use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleTypographyTest extends TestCase
{
    private function element(array $appearance): array
    {
        return ['id' => 'schedule', 'type' => 'schedule', 'editorName' => 'Schedule', 'contentId' => 'content', 'appearance' => $appearance];
    }

    public function test_all_roles_reuse_text_typography_and_preserve_sparse_placement_settings(): void
    {
        $values = ['fontFamilyId' => 'inter', 'fontSize' => 'l', 'fontWeight' => 700, 'italic' => true, 'underline' => true, 'strikethrough' => false, 'colorId' => 'terracotta-text', 'alignment' => 'center', 'lineHeight' => 'relaxed', 'letterSpacing' => 'wide'];
        $appearance = ['typography' => array_fill_keys(ScheduleAppearance::TYPOGRAPHY_ROLES, $values), 'dateFormat' => 'weekday', 'timeFormat' => '24-hour', 'showDescriptions' => false, 'showLocations' => false, 'showMapLinks' => false, 'dayGap' => 'l', 'contentWidth' => 'narrow', 'contentAlignment' => 'center'];
        $element = $this->element($appearance);
        $this->assertSame($element, app(WebsiteElementValidator::class)->validate($element));
        $number = $this->element(['typography' => ['activityTitle' => ['fontFamilyId' => 'inter', 'fontWeight' => 700.0]]]);
        $this->assertSame($number, app(WebsiteElementValidator::class)->validate($number));
        $plain = $this->element([]);
        unset($plain['appearance']);
        $this->assertSame($plain, app(WebsiteElementValidator::class)->validate($this->element(['typography' => ['dayHeading' => []]])));
    }

    #[DataProvider('invalidTypography')]
    public function test_invalid_typography_is_rejected_for_every_role(array $values): void
    {
        foreach (ScheduleAppearance::TYPOGRAPHY_ROLES as $role) {
            try {
                app(WebsiteElementValidator::class)->validate($this->element(['typography' => [$role => $values]]));
                $this->fail('Accepted invalid typography: '.$role);
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($role, array_key_first($exception->errors()));
            }
        }
    }

    public static function invalidTypography(): array
    {
        return array_map(fn ($value) => [$value], [
            ['fontFamilyId' => 'unknown'], ['fontFamilyId' => 'quicksand', 'italic' => true],
            ['fontFamilyId' => 'great-vibes', 'fontWeight' => 700], ['fontSize' => 'huge'], ['fontWeight' => '700'],
            ['italic' => 1], ['colorId' => ''], ['lineHeight' => 2], ['responsive' => ['mobile' => ['fontWeight' => 700]]],
            ['secret' => true], ['glow' => 'soft'], ['fontSize' => null], ['underline' => 'false'],
        ]);
    }

    #[DataProvider('invalidSettings')]
    public function test_strict_new_placement_settings(array $appearance): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteElementValidator::class)->validate($this->element($appearance));
    }

    public static function invalidSettings(): array
    {
        return array_map(fn ($value) => [$value], [
            ['typography' => ['unknown' => []]], ['showDescriptions' => 'false'], ['showLocations' => null],
            ['showMapLinks' => 0], ['dateFormat' => 'numeric'], ['timeFormat' => 'am-pm'], ['dayGap' => 12],
            ['contentWidth' => 600], ['contentAlignment' => 'stretch'], ['showDescriptions' => 1],
        ]);
    }

    public function test_role_json_objects_and_nested_responsive_objects_are_required(): void
    {
        foreach (['{"typography":[]}', '{"typography":{"dayHeading":[]}}', '{"typography":{"dayHeading":{"responsive":[]}}}', '{"typography":{"dayHeading":{"responsive":{"mobile":[]}}}}'] as $json) {
            $this->assertFalse(ScheduleAppearance::validJson(json_decode($json)));
        }
        $this->assertTrue(ScheduleAppearance::validJson(json_decode('{"typography":{"dayHeading":{}}}')));
    }

    public function test_nested_group_role_resources_use_template_and_website_boundaries(): void
    {
        $placement = $this->element(['typography' => ['activityTitle' => ['fontFamilyId' => 'inter', 'colorId' => 'known-color']]]);
        unset($placement['contentId']);
        $placement['days'] = [];
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$placement]];
        $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'group']]]]]];
        $validator = app(WebsiteSectionContentValidator::class);
        $this->assertEquals($content, $validator->validate('blank', $content, null, ['inter'], ['known-color']));
        foreach ([[['quicksand'], ['known-color']], [['inter'], ['different-color']]] as [$fonts, $colors]) {
            try {
                $validator->validate('blank', $content, null, $fonts, $colors);
                $this->fail('Unknown nested Schedule resource accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $content['compositions']['shared']['childFlow']['elements'][0]['children'][0]['appearance']['typography']['activityTitle']['responsive'] = ['mobile' => ['fontSize' => 's']];
        $this->expectException(ValidationException::class);
        $validator->validate('blank', $content);
    }
}
