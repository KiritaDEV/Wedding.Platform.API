<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteElementValidatorTest extends TestCase
{
    private WebsiteElementValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WebsiteElementValidator(new CompositionGroupValidator);
    }

    #[DataProvider('validPrimitiveProvider')]
    public function test_every_active_primitive_accepts_its_canonical_shape(array $element): void
    {
        $this->assertSame($element, $this->validator->validate($element));
    }

    public function test_generic_primitives_accept_and_preserve_hidden_state(): void
    {
        $elements = [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]], 'isHidden' => true],
            ['id' => 'rich', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]], 'isHidden' => true],
            ['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1', 'isHidden' => true],
            ['id' => 'accordion', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => [], 'isHidden' => true],
            ['id' => 'schedule', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'items' => [], 'isHidden' => true],
            ['id' => 'people', 'type' => 'people', 'editorName' => 'People 1', 'groups' => [], 'isHidden' => true],
            ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [], 'isHidden' => true],
            ['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1', 'isHidden' => true],
        ];

        foreach ($elements as $element) {
            $this->assertSame($element, $this->validator->validate($element));
        }
    }

    public function test_generic_blocks_accept_strict_responsive_outer_spacing_and_normalize_sparse_defaults(): void
    {
        $elements = [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]],
            ['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1'],
            ['id' => 'accordion', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => []],
            ['id' => 'schedule', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'items' => []],
            ['id' => 'people', 'type' => 'people', 'editorName' => 'People 1', 'groups' => []],
            ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => []],
            ['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1'],
        ];
        $appearance = [
            'outerSpacing' => ['top' => 'xs', 'right' => 's', 'bottom' => 'm', 'left' => 'xl'],
            'responsive' => [
                'tablet' => ['outerSpacing' => ['top' => 'l']],
                'mobile' => ['outerSpacing' => ['right' => 'none', 'left' => 'xs']],
            ],
        ];
        $expected = [
            'outerSpacing' => $appearance['outerSpacing'],
            'responsive' => [
                'tablet' => ['outerSpacing' => ['top' => 'l']],
                'mobile' => ['outerSpacing' => ['left' => 'xs']],
            ],
        ];

        foreach ($elements as $element) {
            $validated = $this->validator->validate([...$element, 'appearance' => $appearance]);
            $this->assertSame($expected, $validated['appearance']);
            $this->assertInvalid([...$element, 'appearance' => ['outerSpacing' => ['top' => 'huge']]]);
            $this->assertInvalid([...$element, 'appearance' => ['outerSpacing' => ['horizontal' => 'm']]]);
            $this->assertInvalid([...$element, 'appearance' => ['innerSpacing' => ['top' => 's']]]);
        }
    }

    public function test_date_block_accepts_sparse_bounded_appearance(): void
    {
        $element = [
            'id' => 'date', 'type' => 'date', 'editorName' => 'Date 1',
            'appearance' => ['format' => 'short', 'showWeekday' => false, 'alignment' => 'end', 'fontFamilyId' => 'modern-sans', 'fontSize' => 'l', 'fontWeight' => 700, 'lineHeight' => 'relaxed', 'letterSpacing' => 'wide', 'colorId' => 'accent', 'responsive' => ['mobile' => ['fontSize' => 's', 'alignment' => 'center']]],
        ];

        $this->assertSame($element, $this->validator->validate($element));
        foreach (['6xl', '7xl'] as $size) {
            $appearance = ['fontSize' => $size, 'responsive' => ['tablet' => ['fontSize' => '7xl'], 'mobile' => ['fontSize' => '6xl']]];
            $this->assertSame($appearance, $this->validator->validate([...$element, 'appearance' => $appearance])['appearance']);
        }
        foreach (['format' => 'custom', 'alignment' => 'justify', 'textStyle' => 'heading'] as $key => $value) {
            $this->assertInvalid([...$element, 'appearance' => [$key => $value]]);
        }
    }

    public function test_date_and_divider_accept_distinct_effect_contracts_and_prune_inactive_colors(): void
    {
        $date = $this->validator->validate(['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1', 'appearance' => ['textShadow' => 'strong', 'textShadowColorId' => 'ink', 'glow' => 'medium', 'glowColorId' => 'gold']]);
        $divider = $this->validator->validate(['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1', 'appearance' => ['shadow' => 'strong', 'shadowColorId' => 'ink', 'glow' => 'medium', 'glowColorId' => 'gold']]);
        $this->assertSame('strong', $date['appearance']['textShadow']);
        $this->assertSame('strong', $divider['appearance']['shadow']);
        $this->assertSame([], $this->validator->validate(['id' => 'date-none', 'type' => 'date', 'editorName' => 'Date 1', 'appearance' => ['textShadow' => 'none', 'textShadowColorId' => 'ink']])['appearance']);
        $this->assertSame([], $this->validator->validate(['id' => 'divider-none', 'type' => 'divider', 'editorName' => 'Divider 1', 'appearance' => ['shadow' => 'none', 'shadowColorId' => 'ink']])['appearance']);
        $this->assertInvalid(['id' => 'date-bad', 'type' => 'date', 'editorName' => 'Date 1', 'appearance' => ['textShadow' => 'extreme']]);
        $this->assertInvalid(['id' => 'divider-bad', 'type' => 'divider', 'editorName' => 'Divider 1', 'appearance' => ['shadow' => 'extreme']]);
    }

    public function test_accordion_is_strict_bounded_and_requires_unique_item_ids(): void
    {
        $element = ['id' => 'accordion', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => [
            ['id' => 'item-1', 'title' => 'Travel', 'content' => "Allow extra\r\ntime."],
        ]];
        $expected = $element;
        $expected['items'][0]['content'] = 'Allow extra time.';
        $this->assertSame($expected, $this->validator->validate($element));
        $this->assertSame([], $this->validator->validate([...$element, 'items' => []])['items']);
        $this->assertInvalid([...$element, 'unexpected' => true]);
        $this->assertInvalid([...$element, 'items' => [$element['items'][0], $element['items'][0]]]);
        $this->assertInvalid([...$element, 'items' => array_fill(0, 51, $element['items'][0])]);
    }

    public function test_schedule_is_strict_bounded_and_uses_canonical_time_and_unique_item_ids(): void
    {
        $element = ['id' => 'schedule', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'items' => [
            ['id' => 'item-1', 'time' => '15:30', 'title' => 'Ceremony', 'details' => "Garden\r\nlevel"],
        ]];
        $expected = $element;
        $expected['items'][0]['details'] = 'Garden level';
        $this->assertSame($expected, $this->validator->validate($element));
        $this->assertSame([], $this->validator->validate([...$element, 'items' => []])['items']);
        foreach (['3:30 PM', '24:00', '15:60'] as $time) {
            $this->assertInvalid([...$element, 'items' => [[...$element['items'][0], 'time' => $time]]]);
        }
        $this->assertInvalid([...$element, 'unexpected' => true]);
        $this->assertInvalid([...$element, 'items' => [$element['items'][0], $element['items'][0]]]);
        $this->assertInvalid([...$element, 'items' => array_fill(0, 101, $element['items'][0])]);
    }

    public function test_generic_editor_names_are_required_normalized_and_unicode_bounded(): void
    {
        $elements = [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]],
            ['id' => 'rich', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]],
            ['id' => 'date', 'type' => 'date', 'editorName' => 'Date 1'],
            ['id' => 'accordion', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => []],
            ['id' => 'schedule', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'items' => []],
            ['id' => 'people', 'type' => 'people', 'editorName' => 'People 1', 'groups' => []],
            ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => []],
            ['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1'],
            ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => []],
        ];
        foreach ($elements as $element) {
            $unnamed = $element;
            unset($unnamed['editorName']);
            $this->assertInvalid($unnamed);
        }

        $normalized = $this->validator->validate(['id' => 'text', 'type' => 'text', 'editorName' => "  Welcome\n  message  ", 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]]);
        $this->assertSame('Welcome message', $normalized['editorName']);
        $this->assertSame(str_repeat('😀', 80), $this->validator->validate(['id' => 'text', 'type' => 'text', 'editorName' => str_repeat('😀', 80), 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => '']]]]]])['editorName']);
        $this->assertInvalid(['id' => 'text', 'type' => 'text', 'editorName' => str_repeat('😀', 81), 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => '']]]]]]);
        $this->assertInvalid(['id' => 'text', 'type' => 'text', 'editorName' => " \n ", 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => '']]]]]]]]]]]);
        $this->assertInvalid(['id' => 'heading', 'type' => 'heading', 'editorName' => 'Heading 1', 'text' => 'Heading']);
    }

    public static function validPrimitiveProvider(): array
    {
        $mediaId = '01J00000000000000000000000';

        return [
            'heading' => [['id' => 'heading-1', 'type' => 'heading', 'text' => 'Welcome']],
            'text' => [['id' => 'text-1', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Body']]]]], 'appearance' => []]],
            'date' => [['id' => 'date-block-1', 'type' => 'date', 'editorName' => 'Date 1']],
            'accordion' => [['id' => 'accordion-1', 'type' => 'accordion', 'editorName' => 'Accordion 1', 'items' => [['id' => 'item-1', 'title' => 'Travel', 'content' => 'Allow extra time.']]]],
            'schedule' => [['id' => 'schedule-1', 'type' => 'schedule', 'editorName' => 'Schedule 1', 'items' => [['id' => 'item-1', 'time' => '15:30', 'title' => 'Ceremony', 'details' => 'Garden level']]]],
            'people' => [['id' => 'people-1', 'type' => 'people', 'editorName' => 'People 1', 'groups' => [['id' => 'friends', 'name' => 'Friends', 'people' => [['id' => 'alex', 'name' => 'Alex']]]]]],
            'image' => [['id' => 'image-1', 'type' => 'image', 'mediaId' => $mediaId]],
            'media' => [['id' => 'media-1', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'item-1', 'type' => 'image', 'mediaId' => $mediaId, 'alt' => 'Wedding portrait']]]],
            'divider' => [['id' => 'divider-1', 'type' => 'divider', 'editorName' => 'Divider 1']],
            'quote' => [['id' => 'quote-1', 'type' => 'quote', 'text' => 'Always', 'attribution' => 'Us']],
            'cta' => [['id' => 'cta-1', 'type' => 'cta', 'label' => 'Respond', 'action' => ['type' => 'rsvp']]],
            'event date' => [['id' => 'date-1', 'type' => 'eventDate']],
            'event time' => [['id' => 'time-1', 'type' => 'eventTime']],
            'countdown' => [['id' => 'countdown-1', 'type' => 'countdown']],
        ];
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_rejects_unknown_top_level_keys(array $element): void
    {
        $this->assertInvalid([...$element, 'unexpected' => true]);
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_requires_a_known_discriminator(array $element): void
    {
        $missing = $element;
        unset($missing['type']);
        $this->assertInvalid($missing);
        $this->assertInvalid([...$element, 'type' => 'unknown']);
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_requires_a_bounded_nonblank_id(array $element): void
    {
        $this->assertInvalid([...$element, 'id' => '   ']);
        $this->assertInvalid([...$element, 'id' => str_repeat('x', 256)]);
    }

    public static function primitiveProvider(): array
    {
        return self::validPrimitiveProvider();
    }

    public function test_ids_are_trimmed_without_imposing_ulid_or_prefix_semantics(): void
    {
        $validated = $this->validator->validate(['id' => '  arbitrary-client-id  ', 'type' => 'divider', 'editorName' => 'Divider 1']);

        $this->assertSame('arbitrary-client-id', $validated['id']);
    }

    public function test_divider_accepts_only_semantic_widths(): void
    {
        $base = ['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1'];
        foreach (['small', 'medium', 'large', 'full'] as $width) {
            $candidate = [...$base, 'appearance' => ['width' => $width]];
            $this->assertSame($candidate, $this->validator->validate($candidate));
        }
        foreach ([0, 37, 100, '37', 37.5] as $width) {
            $this->assertInvalid([...$base, 'appearance' => ['width' => $width]]);
        }
        $this->assertInvalid([...$base, 'appearance' => ['styleId' => 'botanical-vine']]);
    }

    public function test_divider_accepts_continuous_integer_opacity(): void
    {
        $base = ['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1'];
        $validated = $this->validator->validate([...$base, 'appearance' => ['opacity' => 63]]);
        $this->assertSame(63, $validated['appearance']['opacity']);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 24]]);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 101]]);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 63.5]]);
    }

    public function test_text_limits_and_required_fields_are_enforced(): void
    {
        $this->assertInvalid(['id' => 'heading', 'type' => 'heading']);
        $this->assertInvalid(['id' => 'heading', 'type' => 'heading', 'text' => str_repeat('x', 256)]);
        $this->assertInvalid(['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => str_repeat('x', 20001)]]]]]]);
        $this->assertInvalid(['id' => 'quote', 'type' => 'quote', 'text' => str_repeat('x', 5001)]);
        $this->assertInvalid(['id' => 'quote', 'type' => 'quote', 'text' => 'Quote', 'attribution' => str_repeat('x', 256)]);
    }

    public function test_image_requires_a_ulid_media_reference(): void
    {
        $this->assertInvalid(['id' => 'image', 'type' => 'image', 'mediaId' => 'not-a-ulid']);
        $this->assertInvalid(['id' => 'image', 'type' => 'image', 'mediaId' => '81J00000000000000000000000']);
    }

    #[DataProvider('ctaActionProvider')]
    public function test_every_canonical_cta_action_is_accepted(array $action): void
    {
        $element = ['id' => 'cta', 'type' => 'cta', 'label' => 'Go', 'action' => $action];
        $this->assertSame($element, $this->validator->validate($element));
    }

    public static function ctaActionProvider(): array
    {
        return [
            'rsvp' => [['type' => 'rsvp']],
            'scroll' => [['type' => 'scrollToSection', 'sectionId' => 'section-1']],
            'schedule' => [['type' => 'viewSchedule']],
            'gallery' => [['type' => 'viewGallery']],
            'top' => [['type' => 'backToTop']],
            'external' => [['type' => 'externalUrl', 'url' => 'https://example.com/path']],
        ];
    }

    public function test_cta_actions_strictly_enforce_their_payloads(): void
    {
        $base = ['id' => 'cta', 'type' => 'cta', 'label' => 'Go'];
        $this->assertInvalid([...$base, 'action' => ['type' => 'scrollToSection']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'scrollToSection', 'sectionId' => 'section', 'url' => 'https://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl', 'url' => 'not-a-url']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl', 'url' => 'http://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'rsvp', 'url' => 'https://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'rsvp', 'href' => '/rsvp']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'unknown']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'viewVenue']]);
    }

    public function test_media_enforces_accessibility_and_homogeneous_collections(): void
    {
        $mediaId = (string) Str::ulid();
        $secondMediaId = (string) Str::ulid();
        $this->assertSame(['id' => 'empty', 'type' => 'media', 'editorName' => 'Media 1', 'items' => []], $this->validator->validate(['id' => 'empty', 'type' => 'media', 'editorName' => 'Media 1', 'items' => []]));
        $this->assertInvalid(['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'image', 'type' => 'image', 'mediaId' => $mediaId]]]);
        $this->assertInvalid(['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'video', 'type' => 'video', 'url' => 'https://example.com/video.mp4', 'autoplay' => true]]]);
        $this->assertInvalid(['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [
            ['id' => 'image', 'type' => 'image', 'mediaId' => $mediaId, 'alt' => 'Portrait'],
            ['id' => 'video', 'type' => 'video', 'url' => 'https://example.com/video.mp4'],
        ]]);
        $this->assertInvalid(['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'image', 'type' => 'image', 'mediaId' => $mediaId, 'alt' => 'Portrait', 'caption' => 'No embedded captions']]]);
        $video = ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'video', 'type' => 'video', 'url' => 'https://example.com/video.mp4', 'controls' => true]]];
        $this->assertSame($video, $this->validator->validate($video));
        foreach (['https://youtube.com/watch?v=abc', 'https://www.youtube.com/shorts/abc', 'https://studio.youtube.com/video/abc', 'https://youtu.be/abc', 'https://vimeo.com/123', 'https://player.vimeo.com/video/123'] as $providerUrl) {
            $this->assertInvalid([...$video, 'items' => [[...$video['items'][0], 'url' => $providerUrl]]]);
        }
        foreach (['https://cdn.example.com/source', 'https://cdn.example.com/source?token=signed&expires=123'] as $directUrl) {
            $expected = [...$video, 'items' => [[...$video['items'][0], 'url' => $directUrl]]];
            $this->assertSame($expected, $this->validator->validate($expected));
        }
        $carousel = ['id' => 'media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [
            ['id' => 'one', 'type' => 'image', 'mediaId' => $mediaId, 'alt' => 'One'],
            ['id' => 'two', 'type' => 'image', 'mediaId' => $secondMediaId, 'alt' => 'Two'],
        ], 'presentation' => ['mode' => 'carousel', 'alignment' => 'center', 'fit' => 'cover', 'carousel' => ['autoplay' => true, 'interval' => 5000, 'arrows' => true, 'dots' => true, 'loop' => false], 'responsive' => ['mobile' => ['mode' => 'carousel', 'width' => 'full', 'aspectRatio' => 'square']]], 'appearance' => ['corners' => 'soft', 'frame' => 'line', 'shadow' => 'medium']];
        $this->assertSame($carousel, $this->validator->validate($carousel));
        $this->assertInvalid([...$carousel, 'presentation' => [...$carousel['presentation'], 'carousel' => ['style' => 'peek']]]);
        $this->assertInvalid([...$carousel, 'presentation' => [...$carousel['presentation'], 'stacked' => ['style' => 'polaroid']]]);
        foreach (['grid', 'masonry', 'stack', 'stacked'] as $legacyMode) {
            $this->assertInvalid([...$carousel, 'presentation' => ['mode' => $legacyMode]]);
        }
        foreach ([
            [...$carousel, 'items' => [[...$carousel['items'][0], 'zoom' => '1.5']]],
            [...$carousel, 'items' => [[...$carousel['items'][0], 'focalPoint' => ['x' => '0.5', 'y' => 0.5]]]],
            [...$carousel, 'presentation' => ['mode' => 'carousel', 'carousel' => ['interval' => '5000']]],
            [...$video, 'items' => [[...$video['items'][0], 'controls' => 1]]],
            [...$carousel, 'presentation' => ['mode' => 'carousel', 'carousel' => ['autoplay' => 'true']]],
        ] as $nonCanonical) {
            $this->assertInvalid($nonCanonical);
        }
        $this->assertInvalid([...$carousel, 'presentation' => ['mode' => 'carousel', 'columns' => 3]]);
        $this->assertInvalid([...$carousel, 'motion' => ['type' => 'fade']]);
        $this->assertInvalid([...$carousel, 'appearance' => ['frameSize' => 'large']]);
        $this->assertInvalid([...$carousel, 'presentation' => ['responsive' => ['mobile' => ['alignment' => 'end']]]]);
    }

    public function test_dynamic_elements_reject_copied_event_values(): void
    {
        foreach (['eventDate', 'eventTime', 'countdown'] as $type) {
            foreach (['eventDate', 'startTime', 'timeZone', 'startsAtUtc'] as $field) {
                $this->assertInvalid(['id' => $type, 'type' => $type, $field => 'copied']);
            }
        }
    }

    public function test_people_block_preserves_order_and_validates_unique_stable_ids(): void
    {
        $mediaId = (string) Str::ulid();
        $element = ['id' => 'people', 'type' => 'people', 'editorName' => 'People 1', 'groups' => [[
            'id' => 'friends', 'name' => 'Friends', 'people' => [
                ['id' => 'two', 'name' => 'Alex', 'role' => null, 'media' => ['assetId' => $mediaId, 'focalPoint' => ['x' => .4, 'y' => .6], 'zoom' => 2]],
                ['id' => 'one', 'name' => 'Jane'],
            ],
        ]], 'appearance' => ['presentation' => 'cards']];
        $this->assertSame($element, $this->validator->validate($element));
        $duplicate = $element;
        $duplicate['groups'][0]['people'][1]['id'] = 'two';
        $this->assertInvalid($duplicate);
    }

    private function assertInvalid(array $element): void
    {
        try {
            $this->validator->validate($element);
            $this->fail('Expected the element to be invalid.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertTreeInvalid(array $elements): void
    {
        try {
            $this->validator->validateTree($elements);
            $this->fail('Expected the element tree to be invalid.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
