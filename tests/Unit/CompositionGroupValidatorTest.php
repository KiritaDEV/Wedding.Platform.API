<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompositionGroupValidatorTest extends TestCase
{
    private WebsiteElementValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WebsiteElementValidator(new CompositionGroupValidator);
    }

    public function test_group_accepts_layout_children_and_one_nested_group(): void
    {
        $group = ['id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hello']]]]]],
            ['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1'],
            ['id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'nested-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]]], 'layout' => ['direction' => 'vertical']],
        ], 'layout' => ['width' => 'narrow', 'direction' => 'horizontal', 'gap' => 'l', 'padding' => ['top' => 's', 'right' => 'm', 'bottom' => 's', 'left' => 'm'], 'alignment' => 'center', 'division' => '50-50', 'responsive' => ['tablet' => ['width' => 'medium'], 'mobile' => ['width' => 'full', 'direction' => 'vertical', 'gap' => 's']]]];
        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_canonical_rich_text_with_multiple_paragraphs_and_marks(): void
    {
        $textBlock = ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Lorem '], ['text' => 'ipsum', 'marks' => ['bold' => true]]]],
            ['type' => 'paragraph', 'children' => [['text' => 'One', 'marks' => ['italic' => true]]]],
            ['type' => 'paragraph', 'children' => [['text' => 'Two']]],
        ]]];
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$textBlock], 'layout' => ['direction' => 'vertical']];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_background_appearance(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'appearance' => ['backgroundColorId' => 'sage-accent', 'shadow' => 'medium', 'decorativeAppearance' => ['background' => ['texture' => 'paper', 'textureStrength' => 40, 'pattern' => 'botanical', 'patternStrength' => 60]]]];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_canonical_background_media_divisions_and_sparse_opacity(): void
    {
        $id = '01M00000000000000000000000';
        foreach (['50-50', '60-40', '40-60', 'thirds'] as $division) {
            $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
                'backgroundMedia' => ['assetId' => $id, 'focalPoint' => ['x' => .2, 'y' => .8], 'zoom' => 1.7],
                'appearance' => ['backgroundImageOpacity' => 45], 'layout' => ['direction' => 'horizontal', 'division' => $division],
            ];
            $this->assertSame($group, $this->validator->validate($group));
        }
        $sparse = $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'appearance' => ['backgroundImageOpacity' => 100]]);
        $this->assertSame([], $sparse['appearance']);
    }

    public function test_group_rejects_obsolete_division_and_malformed_background_media(): void
    {
        foreach ([
            ['layout' => ['columns' => 'equal-2']],
            ['layout' => ['division' => '70-30']],
            ['backgroundMedia' => ['assetId' => 'not-a-ulid']],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'focalPoint' => ['x' => 1.1, 'y' => .5]]],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'zoom' => '1.5']],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'fit' => 'cover']],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'responsive' => ['watch' => []]]],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'responsive' => ['mobile' => ['crop' => 'smart']]]],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'responsive' => ['mobile' => ['assetId' => 'invalid']]]],
            ['backgroundMedia' => ['assetId' => '01M00000000000000000000000', 'responsive' => ['tablet' => ['zoom' => '1.5']]]],
            ['appearance' => ['backgroundImageOpacity' => '50']],
        ] as $state) {
            try {
                $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], ...$state]);
                $this->fail('Expected validation failure.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_group_rejects_obsolete_empty_responsive_objects(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'backgroundMedia' => [
            'assetId' => '01M00000000000000000000000', 'responsive' => ['tablet' => [], 'mobile' => []],
        ]]);
    }

    public function test_owner_explicit_none_is_preserved_and_cannot_have_framing(): void
    {
        $background = ['assetId' => null];
        $group = $this->validator->validate([
            'id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
            'backgroundMedia' => $background,
        ], fn (array $child): array => $child);

        $this->assertSame($background, $group['backgroundMedia']);

        $this->expectException(ValidationException::class);
        $this->validator->validate([
            'id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
            'backgroundMedia' => ['assetId' => null, 'zoom' => 1.5],
        ], fn (array $child): array => $child);
    }

    public function test_group_and_nested_generic_children_preserve_hidden_state(): void
    {
        $group = ['id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true, 'children' => [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hidden child']]]]], 'isHidden' => true],
            ['id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true, 'children' => []],
        ]];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_rejects_editor_metadata_from_nested_rich_text_runs(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'nested-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Copy', 'editorMetadata' => true]]],
        ]]]], 'layout' => []];

        $this->expectException(ValidationException::class);
        $this->validator->validate($group);
    }

    public function test_group_rejects_old_placeholder_shape_unknown_layout_and_third_level(): void
    {
        foreach ([
            ['id' => 'old', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'composition' => 'flow', 'children' => []],
            ['id' => 'bad', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'layout' => ['gap' => 'huge']],
            ['id' => 'unsupported', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'heading', 'type' => 'heading', 'text' => 'No']]],
            ['id' => 'one', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'two', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'three', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => []]]]]],
        ] as $element) {
            try {
                $this->validator->validate($element);
                $this->fail('Expected validation failure.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_group_tree_ids_are_unique(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'same', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'A']]]]]], ['id' => 'same', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'B']]]]]]]]);
    }

    public function test_group_preserves_distinct_outer_and_inner_spacing_with_sparse_device_overrides(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
            'appearance' => ['outerSpacing' => ['top' => 'm', 'right' => 'none'], 'responsive' => ['tablet' => ['outerSpacing' => ['top' => 's']], 'mobile' => ['outerSpacing' => ['left' => 'xs']]]],
            'layout' => ['padding' => ['top' => 's']]];

        $validated = $this->validator->validate($group);
        $this->assertSame(['top' => 'm'], $validated['appearance']['outerSpacing']);
        $this->assertSame(['top' => 's'], $validated['appearance']['responsive']['tablet']['outerSpacing']);
        $this->assertSame(['left' => 'xs'], $validated['appearance']['responsive']['mobile']['outerSpacing']);
        $this->assertSame(['top' => 's'], $validated['layout']['padding']);
    }
}
