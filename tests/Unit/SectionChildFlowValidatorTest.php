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

    public function test_rsvp_requires_one_specialized_slot_and_restricts_generic_tree_types(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $text = ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Kindly Respond']]]]]];
        $valid = $this->compositionContent(['elements' => [$text], 'order' => [
            ['kind' => 'element', 'id' => 'text'],
            ['kind' => 'specialized', 'key' => 'content'],
        ]]);
        $this->assertSame($valid, $validator->validate('rsvp', $valid, ['text', 'divider', 'media', 'compositionGroup']));

        $invalid = [
            ['semantic' => ['heading' => 'Legacy'], 'compositions' => $valid['compositions']],
            $this->compositionContent(['elements' => [$text], 'order' => [['kind' => 'element', 'id' => 'text']]]),
            $this->compositionContent(['elements' => [$text], 'order' => [['kind' => 'specialized', 'key' => 'content'], ['kind' => 'specialized', 'key' => 'content'], ['kind' => 'element', 'id' => 'text']]]),
            $this->compositionContent(['elements' => [['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1']]]], 'order' => [['kind' => 'element', 'id' => 'group'], ['kind' => 'specialized', 'key' => 'content']]]),
        ];
        foreach ($invalid as $content) {
            try {
                $validator->validate('rsvp', $content, ['text', 'divider', 'media', 'compositionGroup']);
                $this->fail('Invalid RSVP composition was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rsvp_accepts_only_canonical_sparse_runtime_appearance(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $content = $this->compositionContent(['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]);
        $content['semantic']['runtimeAppearance'] = [
            'status' => ['fontFamilyId' => 'inter', 'fontSize' => '2xl', 'colorId' => 'heading', 'responsive' => ['mobile' => ['fontSize' => 'l', 'alignment' => 'center']]],
            'guestName' => ['fontWeight' => 700],
            'responseLabel' => ['textTransform' => 'uppercase'],
            'supporting' => ['lineHeight' => 'relaxed'],
            'choice' => ['layout' => 'segmented', 'selected' => ['emphasis' => 'bold', 'borderColorId' => 'accent'], 'responsive' => ['mobile' => ['direction' => 'column', 'size' => 'large']]],
            'action' => ['variant' => 'outline', 'radius' => 'pill', 'typography' => ['fontFamilyId' => 'inter'], 'responsive' => ['mobile' => ['width' => 'full', 'alignment' => 'center']]],
        ];
        $this->assertSame($content, $validator->validate('rsvp', $content, null, ['inter'], ['heading', 'accent']));

        foreach ([
            ['unknown' => []],
            ['status' => ['unknown' => true]],
            ['choice' => ['layout' => 'buttons']],
            ['action' => ['width' => 'overflow']],
            ['previewState' => 'completed'],
            ['guests' => [['name' => 'Alex Santos']]],
            ['status' => ['fontFamilyId' => 'unknown']],
            ['choice' => ['selected' => ['borderColorId' => 'unknown']]],
        ] as $runtimeAppearance) {
            $invalid = $this->compositionContent(['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]);
            $invalid['semantic']['runtimeAppearance'] = $runtimeAppearance;
            try {
                $validator->validate('rsvp', $invalid, null, ['inter'], ['heading', 'accent']);
                $this->fail('Invalid RSVP runtime appearance was accepted.');
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
