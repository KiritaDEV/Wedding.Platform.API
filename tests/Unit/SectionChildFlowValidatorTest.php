<?php

namespace Tests\Unit;

use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SectionChildFlowValidatorTest extends TestCase
{
    public function test_date_section_is_not_editable(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('date', ['heading' => 'When', 'description' => 'At noon']);
    }

    public function test_faq_section_is_not_editable(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('faq', ['heading' => 'Questions', 'items' => []]);
    }

    public function test_schedule_section_is_not_editable(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('schedule', ['heading' => 'Schedule', 'items' => []]);
    }

    public function test_venue_section_is_not_editable(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('venue', [
            'heading' => 'Venue',
            'name' => 'Garden Pavilion',
            'address' => 'Main Street',
            'description' => '',
        ]);
    }

    public function test_hero_accepts_the_same_empty_generic_only_flow_as_blank(): void
    {
        $content = $this->compositionContent(['elements' => [], 'order' => []]);

        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('hero', $content));
    }

    public function test_blank_accepts_empty_and_ordered_generic_only_flows(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $empty = $this->compositionContent(['elements' => [], 'order' => []]);
        $this->assertSame($empty, $validator->validate('blank', $empty, ['text']));

        $element = ['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hello']]]]]];
        $ordered = $this->compositionContent(['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'a']]]);
        $this->assertSame($ordered, $validator->validate('blank', $ordered, ['text']));
    }

    public function test_hero_and_blank_validate_inline_text_color_references(): void
    {
        $element = ['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => '&', 'colorId' => 'green']]]]]];
        $content = $this->compositionContent(['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'a']]]);
        foreach (['hero', 'blank'] as $sectionType) {
            $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate($sectionType, $content, ['text'], null, ['green']));
        }

        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('blank', $content, ['text'], null, ['blue']);
    }

    public function test_date_block_is_valid_at_blank_root_and_nested_group_without_persisted_event_data(): void
    {
        $date = ['id' => 'date-1', 'type' => 'date', 'editorName' => 'Ceremony date', 'isHidden' => true];
        $nestedDate = ['id' => 'date-2', 'type' => 'date', 'editorName' => 'Nested date'];
        $group = ['id' => 'group-1', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$nestedDate]];
        $content = $this->compositionContent(['elements' => [$date, $group], 'order' => [
            ['kind' => 'element', 'id' => 'date-1'],
            ['kind' => 'element', 'id' => 'group-1'],
        ]]);

        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate(
            'blank',
            $content,
            ['date', 'compositionGroup'],
        ));
    }

    public function test_blank_rejects_specialized_and_malformed_references(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        foreach ([
            ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]],
            ['elements' => [['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hello']]]]]]], 'order' => [['kind' => 'element', 'id' => 'missing']]],
        ] as $flow) {
            try {
                $validator->validate('blank', $this->compositionContent($flow), ['text']);
                $this->fail('Invalid Blank flow was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function content(): array
    {
        return [
            'heading' => 'When',
            'description' => 'Noon',
            'childFlow' => [
                'elements' => [['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Before']]]]], 'appearance' => []]],
                'order' => [['kind' => 'element', 'id' => 'a'], ['kind' => 'specialized', 'key' => 'content']],
            ],
        ];
    }

    private function compositionContent(array $flow): array
    {
        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => $flow]]];
    }
}
