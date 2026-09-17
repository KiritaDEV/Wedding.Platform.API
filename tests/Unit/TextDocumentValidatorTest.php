<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TextDocumentValidatorTest extends TestCase
{
    public function test_structured_paragraph_text_document_with_supported_marks_is_accepted(): void
    {
        $element = [
            'id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1',
            'document' => ['type' => 'doc', 'children' => [
                ['type' => 'paragraph', 'children' => [['text' => 'Hello ', 'marks' => ['bold' => true]], ['text' => 'world', 'marks' => ['italic' => true, 'underline' => true, 'strikethrough' => true]]]],
            ]],
            'appearance' => ['fontFamilyId' => 'inter', 'fontSize' => 'm', 'fontWeight' => 700, 'lineHeight' => 'relaxed', 'letterSpacing' => 'wide', 'alignment' => 'center', 'colorId' => 'ink', 'responsive' => ['tablet' => ['fontSize' => 'l', 'alignment' => 'start'], 'mobile' => ['fontSize' => 's', 'alignment' => 'end']]],
        ];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    public function test_rejects_link_marks(): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate(['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Bad', 'marks' => ['link' => 'https://example.com']]]],
        ]]]);
    }

    #[DataProvider('invalidTextStructureProvider')]
    public function test_rejects_unknown_fields_and_noncanonical_document_structure(array $element): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTextStructureProvider(): iterable
    {
        $base = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]];
        yield 'unknown top-level field' => [$base + ['editorMetadata' => true]];
        $unknownBlock = $base;
        $unknownBlock['document'] = ['type' => 'doc', 'children' => [['type' => 'heading', 'children' => [['text' => 'No']]]]];
        yield 'unknown document block' => [$unknownBlock];
        $list = $base;
        $list['document'] = [
            'type' => 'doc',
            'children' => [[
                'type' => 'bulletList',
                'items' => [[['text' => 'No']]],
            ]],
        ];
        yield 'list blocks are noncanonical' => [$list];
        $link = $base;
        $link['document'] = [
            'type' => 'doc',
            'children' => [[
                'type' => 'paragraph',
                'children' => [['text' => 'No', 'marks' => ['link' => 'https://example.com']]],
            ]],
        ];
        yield 'link mark is noncanonical' => [$link];
    }

    #[DataProvider('invalidTextAppearanceProvider')]
    public function test_rejects_noncanonical_or_unsupported_text_document_appearance(array $appearance): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate([
            'id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1',
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]],
            'appearance' => $appearance,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTextAppearanceProvider(): iterable
    {
        yield 'unsupported global font weight' => [['fontFamilyId' => 'great-vibes', 'fontWeight' => 700]];
        yield 'unknown appearance field' => [['unknown' => true]];
        yield 'responsive font weight' => [['responsive' => ['mobile' => ['fontWeight' => 700]]]];
        yield 'malformed font weight' => [['fontWeight' => 500]];
        yield 'unknown display size' => [['fontSize' => '8xl']];
        yield 'unknown shadow strength' => [['textShadow' => 'extreme']];
        yield 'malformed shadow color reference' => [['textShadow' => 'soft', 'textShadowColorId' => ['ink']]];
        yield 'unknown glow strength' => [['glow' => 'extreme']];
        yield 'empty glow color reference' => [['glow' => 'soft', 'glowColorId' => '']];
        yield 'raw shadow controls' => [['shadowBlur' => 12]];
    }

    public function test_display_sizes_and_bounded_effects_are_preserved_and_inactive_colors_are_pruned(): void
    {
        $validator = new WebsiteElementValidator(new CompositionGroupValidator);
        foreach (['2xl', '3xl', '4xl', '5xl', '6xl', '7xl'] as $size) {
            $element = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1',
                'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hero']]]]],
                'appearance' => ['fontSize' => $size, 'textShadow' => 'strong', 'textShadowColorId' => 'ink', 'glow' => 'medium', 'glowColorId' => 'accent', 'responsive' => ['tablet' => ['fontSize' => '7xl'], 'mobile' => ['fontSize' => '6xl']]],
            ];
            $this->assertSame($element, $validator->validate($element));
        }

        $inactive = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1',
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Hero']]]]],
            'appearance' => ['textShadow' => 'none', 'textShadowColorId' => 'ink', 'glowColorId' => 'accent'],
        ];
        $this->assertSame([], $validator->validate($inactive)['appearance']);
    }

    public function test_canonical_runs_and_all_supported_marks_are_preserved(): void
    {
        $element = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [[
                'text' => 'Formatted',
                'marks' => ['bold' => true, 'italic' => true, 'underline' => true, 'strikethrough' => true],
            ]]],
        ]]];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    public function test_inline_color_is_preserved_with_existing_marks(): void
    {
        $element = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Neil '], ['text' => '&', 'marks' => ['bold' => true, 'italic' => true], 'colorId' => 'green'], ['text' => ' Hazel']]],
        ]]];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    public function test_request_middleware_null_for_a_canonical_empty_run_is_restored(): void
    {
        $element = ['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => null]]],
        ]]];

        $validated = (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element);

        $this->assertSame('', $validated['document']['children'][0]['children'][0]['text']);
    }

    #[DataProvider('invalidCanonicalRunProvider')]
    public function test_rejects_noncanonical_runs_and_marks(mixed $run): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate([
            'id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1',
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [$run]]]],
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCanonicalRunProvider(): iterable
    {
        yield 'unknown run key' => [['text' => 'Copy', 'editorMetadata' => true]];
        yield 'unknown mark key' => [['text' => 'Copy', 'marks' => ['bold' => true, 'editorMetadata' => true]]];
        yield 'string shorthand run' => ['Copy'];
        yield 'non-object run' => [null];
        yield 'malformed boolean mark' => [['text' => 'Copy', 'marks' => ['bold' => 'true']]];
        yield 'link mark' => [['text' => 'Copy', 'marks' => ['link' => 'https://example.com']]];
        yield 'null marks' => [['text' => 'Copy', 'marks' => null]];
        yield 'null boolean mark' => [['text' => 'Copy', 'marks' => ['bold' => null]]];
        yield 'empty inline color' => [['text' => 'Copy', 'colorId' => '']];
        yield 'malformed inline color' => [['text' => 'Copy', 'colorId' => ['green']]];
        yield 'raw CSS color field' => [['text' => 'Copy', 'color' => '#00FF00']];
    }
}
