<?php

namespace App\Website\Elements;

use App\Website\Capabilities\PlatformFontRegistry;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteElementValidator
{
    private readonly PlatformFontRegistry $fonts;

    public function __construct(private readonly CompositionGroupValidator $groups, ?PlatformFontRegistry $fonts = null)
    {
        $this->fonts = $fonts ?? new PlatformFontRegistry;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public function validate(array $element): array
    {
        return $this->validateAtDepth($element, 0);
    }

    /** @param array<string,mixed> $element @return array<string,mixed> */
    private function validateAtDepth(array $element, int $depth): array
    {
        $type = $this->elementType($element);
        if (in_array($type, [WebsiteElementType::Text, WebsiteElementType::Date, WebsiteElementType::Accordion, WebsiteElementType::Schedule, WebsiteElementType::People, WebsiteElementType::Media, WebsiteElementType::Divider, WebsiteElementType::CompositionGroup], true)
            && is_string($element['editorName'] ?? null)) {
            $element['editorName'] = $this->normalizeEditorName($element['editorName']);
        }

        if ($type === WebsiteElementType::CompositionGroup) {
            if ($depth >= 2) {
                throw ValidationException::withMessages(['element.type' => 'Groups may be nested at most two levels deep.']);
            }

            return $this->groups->validate($element, fn (array $child): array => $this->validateAtDepth($child, $depth + 1));
        }

        return $this->validateLeaf($element);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public function validateTree(array $elements): array
    {
        $validated = array_map(fn (array $element): array => $this->validate($element), $elements);
        $this->groups->assertUniqueTreeIds($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private function validateLeaf(array $element): array
    {
        $type = $this->elementType($element);
        if ($type === WebsiteElementType::CompositionGroup) {
            throw ValidationException::withMessages([
                'element.type' => 'Composition Groups cannot be nested.',
            ]);
        }

        if ($type === WebsiteElementType::Divider) {
            $this->assertDividerJsonTypes($element);
        }
        if ($type === WebsiteElementType::Media) {
            $this->assertMediaItemShapes($element['items'] ?? []);
            $this->assertMediaJsonTypes($element);
        }
        if ($type === WebsiteElementType::Text) {
            $this->restoreCanonicalEmptyTextRuns($element);
        }

        $spacing = in_array($type, [WebsiteElementType::Text, WebsiteElementType::Date, WebsiteElementType::Accordion, WebsiteElementType::Schedule, WebsiteElementType::People, WebsiteElementType::Media, WebsiteElementType::Divider], true)
            ? FourSidedSpacing::extractOuter($element)
            : [];

        $rules = match ($type) {
            WebsiteElementType::Heading => $this->textRules('heading', 255),
            WebsiteElementType::Text => $this->textElementRules(),
            WebsiteElementType::Date => $this->dateElementRules(),
            WebsiteElementType::Accordion => $this->accordionElementRules(),
            WebsiteElementType::Schedule => $this->scheduleElementRules(),
            WebsiteElementType::People => $this->peopleElementRules(),
            WebsiteElementType::Image => $this->imageRules(),
            WebsiteElementType::Media => $this->mediaRules(),
            WebsiteElementType::Divider => $this->dividerRules(),
            WebsiteElementType::Quote => $this->quoteRules(),
            WebsiteElementType::Cta => $this->ctaRules($element),
            WebsiteElementType::EventDate => $this->baseRules('eventDate'),
            WebsiteElementType::EventTime => $this->baseRules('eventTime'),
            WebsiteElementType::Countdown => $this->baseRules('countdown'),
            WebsiteElementType::CompositionGroup => throw new \LogicException('Composition Groups are validated separately.'),
        };

        if (in_array($type, [WebsiteElementType::Text, WebsiteElementType::Date, WebsiteElementType::Accordion, WebsiteElementType::Schedule, WebsiteElementType::People, WebsiteElementType::Media, WebsiteElementType::Divider], true)) {
            $rules['element.isHidden'] = ['sometimes', 'boolean'];
            $rules['element.editorName'] = ['required', 'string', 'max:80', 'not_regex:/^\s*$/u'];
        }

        $validated = Validator::make(['element' => $element], $rules)->validate()['element'];
        $validated['id'] = trim($validated['id']);
        if ($spacing !== []) {
            $validated['appearance'] = FourSidedSpacing::restoreOuter($validated['appearance'] ?? [], $spacing);
        }

        if ($type === WebsiteElementType::Cta && isset($validated['action']['sectionId'])) {
            $validated['action']['sectionId'] = trim($validated['action']['sectionId']);
        }
        if ($type === WebsiteElementType::Media) {
            $kinds = [];
            foreach ($validated['items'] as $index => $item) {
                $kinds[$item['type']] = true;
                if ($item['type'] === 'image' && ($item['decorative'] ?? false) !== true && trim($item['alt'] ?? '') === '') {
                    throw ValidationException::withMessages(["element.items.{$index}.alt" => 'Alt text is required unless the image is decorative.']);
                }
            }
            if (count($kinds) > 1) {
                throw ValidationException::withMessages(['element.items' => 'Mixed image and video collections are not supported yet.']);
            }
            if (count(array_filter($validated['items'], fn (array $item): bool => $item['type'] === 'video')) > 1) {
                throw ValidationException::withMessages(['element.items' => 'Media supports only one video.']);
            }
            $mode = $validated['presentation']['mode'] ?? null;
            if ($mode === 'single' && count($validated['items']) !== 1) {
                throw ValidationException::withMessages(['element.presentation.mode' => 'Single presentation requires exactly one item.']);
            }
            if ($mode === 'carousel' && (count($validated['items']) < 2 || count($kinds) !== 1 || ! isset($kinds['image']))) {
                throw ValidationException::withMessages(['element.presentation.mode' => 'Carousel presentation requires at least two images.']);
            }
            foreach (['tablet', 'mobile'] as $viewport) {
                $responsiveMode = $validated['presentation']['responsive'][$viewport]['mode'] ?? null;
                if ($responsiveMode === 'single' && count($validated['items']) !== 1) {
                    throw ValidationException::withMessages(["element.presentation.responsive.{$viewport}.mode" => 'Single presentation requires exactly one item.']);
                }
                if ($responsiveMode === 'carousel' && (count($validated['items']) < 2 || count($kinds) !== 1 || ! isset($kinds['image']))) {
                    throw ValidationException::withMessages(["element.presentation.responsive.{$viewport}.mode" => 'Carousel presentation requires at least two images.']);
                }
            }
        }
        if (in_array($type, [WebsiteElementType::Text, WebsiteElementType::Date], true)) {
            $this->assertTextFontTuple($validated);
        }
        if (in_array($type, [WebsiteElementType::Text, WebsiteElementType::Date], true)) {
            foreach ([['textShadow', 'textShadowColorId'], ['glow', 'glowColorId']] as [$effect, $color]) {
                if (($validated['appearance'][$effect] ?? 'none') === 'none') {
                    unset($validated['appearance'][$effect], $validated['appearance'][$color]);
                }
            }
        }
        if ($type === WebsiteElementType::Divider) {
            foreach ([['shadow', 'shadowColorId'], ['glow', 'glowColorId']] as [$effect, $color]) {
                if (($validated['appearance'][$effect] ?? 'none') === 'none') {
                    unset($validated['appearance'][$effect], $validated['appearance'][$color]);
                }
            }
        }
        if ($type === WebsiteElementType::Text) {
            $this->assertTextDocument($validated['document']);
            $this->assertTextFontWeight($validated);
        }
        if ($type === WebsiteElementType::Accordion) {
            $ids = array_column($validated['items'], 'id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['element.items' => 'Accordion item IDs must be unique.']);
            }
            $validated['items'] = array_map(function (array $item): array {
                $item['id'] = trim($item['id']);
                $item['content'] = $this->normalizeText($item['content']);

                return $item;
            }, $validated['items']);
        }
        if ($type === WebsiteElementType::Schedule) {
            $ids = array_column($validated['items'], 'id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['element.items' => 'Schedule item IDs must be unique.']);
            }
            $validated['items'] = array_map(function (array $item): array {
                $item['id'] = trim($item['id']);
                $item['details'] = $this->normalizeText($item['details']);

                return $item;
            }, $validated['items']);
        }
        if ($type === WebsiteElementType::People) {
            $groupIds = array_column($validated['groups'], 'id');
            $personIds = collect($validated['groups'])->flatMap(fn (array $group): array => array_column($group['people'], 'id'))->all();
            if (count($groupIds) !== count(array_unique($groupIds))) {
                throw ValidationException::withMessages(['element.groups' => 'People group IDs must be unique.']);
            }
            if (count($personIds) !== count(array_unique($personIds))) {
                throw ValidationException::withMessages(['element.groups' => 'Person IDs must be unique.']);
            }
        }

        return $validated;
    }

    /** @param array<string, mixed> $element */
    private function restoreCanonicalEmptyTextRuns(array &$element): void
    {
        if (! is_array($element['document']['children'] ?? null)) {
            return;
        }
        foreach ($element['document']['children'] as &$block) {
            if (! is_array($block)) {
                continue;
            }
            if (! is_array($block['children'] ?? null)) {
                continue;
            }
            foreach ($block['children'] as &$run) {
                if (is_array($run) && array_key_exists('text', $run) && $run['text'] === null) {
                    $run['text'] = '';
                }
            }
            unset($run);
        }
        unset($block);
    }

    /** @param array<string, mixed> $element */
    private function elementType(array $element): WebsiteElementType
    {
        $value = $element['type'] ?? null;
        $type = is_string($value) ? WebsiteElementType::tryFrom($value) : null;

        if ($type === null) {
            throw ValidationException::withMessages([
                'element.type' => $value === null ? 'The element type is required.' : 'The element type is not supported.',
            ]);
        }

        return $type;
    }

    /** @return array<string, list<string>> */
    private function baseRules(string $type): array
    {
        return [
            'element' => ['required', 'array:id,type'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', "in:{$type}"],
        ];
    }

    /** @return array<string, list<string>> */
    private function dateElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,isHidden,appearance'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:date'],
            'element.appearance' => ['sometimes', 'array:format,showWeekday,alignment,fontFamilyId,fontSize,fontWeight,lineHeight,letterSpacing,textTransform,colorId,textShadow,textShadowColorId,glow,glowColorId,responsive'],
            'element.appearance.format' => ['sometimes', 'in:long,medium,short,numeric'],
            'element.appearance.showWeekday' => ['sometimes', 'boolean'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.fontFamilyId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.fontWeight' => ['sometimes', 'integer', 'in:400,600,700'],
            'element.appearance.lineHeight' => ['sometimes', 'in:tight,normal,relaxed'],
            'element.appearance.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'element.appearance.textTransform' => ['sometimes', 'in:none,uppercase,lowercase,capitalize'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.textShadow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.textShadowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
            'element.appearance.glow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.glowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
            'element.appearance.responsive' => ['sometimes', 'array:tablet,mobile'],
            'element.appearance.responsive.tablet' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.tablet.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.responsive.tablet.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.responsive.mobile' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.mobile.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.responsive.mobile.alignment' => ['sometimes', 'in:start,center,end'],
        ];
    }

    /** @return array<string, list<string>> */
    private function accordionElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,isHidden,items'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:accordion'],
            'element.items' => ['present', 'array', 'max:50'],
            'element.items.*' => ['required', 'array:id,title,content'],
            'element.items.*.id' => $this->idRules(),
            'element.items.*.title' => ['present', 'string', 'max:255'],
            'element.items.*.content' => ['present', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, list<string>> */
    private function scheduleElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,isHidden,items'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:schedule'],
            'element.items' => ['present', 'array', 'max:100'],
            'element.items.*' => ['required', 'array:id,time,title,details'],
            'element.items.*.id' => $this->idRules(),
            'element.items.*.time' => ['present', 'string', 'regex:/^(?:|(?:[01]\d|2[0-3]):[0-5]\d)$/'],
            'element.items.*.title' => ['present', 'string', 'max:255'],
            'element.items.*.details' => ['present', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, list<string>> */
    private function peopleElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,isHidden,groups,appearance'],
            'element.id' => $this->idRules(), 'element.type' => ['required', 'in:people'],
            'element.groups' => ['present', 'array', 'list', 'max:30'],
            'element.groups.*' => ['required', 'array:id,name,people'], 'element.groups.*.id' => $this->idRules(), 'element.groups.*.name' => ['required', 'string', 'max:255'],
            'element.groups.*.people' => ['present', 'array', 'list', 'max:100'],
            'element.groups.*.people.*' => ['required', 'array:id,name,role,media'], 'element.groups.*.people.*.id' => $this->idRules(), 'element.groups.*.people.*.name' => ['required', 'string', 'max:255'], 'element.groups.*.people.*.role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'element.groups.*.people.*.media' => ['sometimes', 'nullable', 'array:assetId,focalPoint,zoom'], 'element.groups.*.people.*.media.assetId' => ['required_with:element.groups.*.people.*.media', 'string', 'ulid'],
            'element.groups.*.people.*.media.focalPoint' => ['sometimes', 'array:x,y'], 'element.groups.*.people.*.media.focalPoint.x' => ['required_with:element.groups.*.people.*.media.focalPoint', 'numeric', 'between:0,1'], 'element.groups.*.people.*.media.focalPoint.y' => ['required_with:element.groups.*.people.*.media.focalPoint', 'numeric', 'between:0,1'], 'element.groups.*.people.*.media.zoom' => ['sometimes', 'numeric', 'between:1,3'],
            'element.appearance' => ['sometimes', 'array:presentation'], 'element.appearance.presentation' => ['sometimes', 'in:portraits,cards,minimal,namesOnly'],
        ];
    }

    /** @return array<string, list<string>> */
    private function textRules(string $type, int $maximum): array
    {
        return [
            'element' => ['required', 'array:id,type,text'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', "in:{$type}"],
            'element.text' => ['present', 'string', "max:{$maximum}"],
        ];
    }

    /** @return array<string, list<string>> */
    private function textElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,document,appearance,isHidden'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:text'],
            'element.document' => ['required', 'array:type,children'],
            'element.document.type' => ['required', 'in:doc'],
            'element.document.children' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'element.document.children.*' => ['required', 'array'],
            'element.appearance' => ['sometimes', 'array:fontFamilyId,fontSize,fontWeight,lineHeight,letterSpacing,alignment,colorId,italic,underline,strikethrough,textTransform,textShadow,textShadowColorId,glow,glowColorId,responsive'],
            'element.appearance.fontFamilyId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.fontWeight' => ['sometimes', 'integer', 'in:400,600,700'],
            'element.appearance.lineHeight' => ['sometimes', 'in:tight,normal,relaxed'],
            'element.appearance.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.textShadow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.textShadowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
            'element.appearance.glow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.glowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
            'element.appearance.italic' => ['sometimes', 'boolean'],
            'element.appearance.underline' => ['sometimes', 'boolean'],
            'element.appearance.strikethrough' => ['sometimes', 'boolean'],
            'element.appearance.textTransform' => ['sometimes', 'in:none,uppercase,lowercase,capitalize'],
            'element.appearance.responsive' => ['sometimes', 'array:tablet,mobile'],
            'element.appearance.responsive.tablet' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.tablet.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.responsive.tablet.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.responsive.mobile' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.mobile.fontSize' => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            'element.appearance.responsive.mobile.alignment' => ['sometimes', 'in:start,center,end'],
        ];
    }

    private function normalizeText(string $text): string
    {
        return preg_replace('/(?:\r\n|[\r\n\x{2028}\x{2029}])+/u', ' ', $text) ?? $text;
    }

    private function normalizeEditorName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /** @param array<string, mixed> $element */
    private function assertDividerJsonTypes(array $element): void
    {
        if (array_key_exists('isHidden', $element) && ! is_bool($element['isHidden'])) {
            throw ValidationException::withMessages(['element.isHidden' => 'Divider visibility must be a JSON boolean.']);
        }
        if (! array_key_exists('appearance', $element)) {
            return;
        }
        $appearance = $element['appearance'];
        if (! is_array($appearance)) {
            throw ValidationException::withMessages(['element.appearance' => 'Divider appearance must be a JSON object.']);
        }
        foreach (['assetId', 'width', 'alignment', 'colorId'] as $field) {
            if (array_key_exists($field, $appearance) && (! is_string($appearance[$field]) || $appearance[$field] === '')) {
                throw ValidationException::withMessages(["element.appearance.{$field}" => 'A nonempty JSON string is required.']);
            }
        }
        if (array_key_exists('opacity', $appearance)) {
            $opacity = $appearance['opacity'];
            if ((! is_int($opacity) && ! is_float($opacity)) || ! is_finite((float) $opacity) || floor($opacity) != $opacity) {
                throw ValidationException::withMessages(['element.appearance.opacity' => 'Divider opacity must be a JSON integer.']);
            }
        }
    }

    /** @return array<string, list<string>> */
    private function dividerRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,appearance,isHidden'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:divider'],
            'element.appearance' => ['sometimes', 'array:assetId,width,alignment,colorId,opacity,shadow,shadowColorId,glow,glowColorId'],
            'element.appearance.assetId' => ['sometimes', 'string', 'min:1', 'max:100'],
            'element.appearance.width' => ['sometimes', 'string', 'in:small,medium,large,full'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.opacity' => ['sometimes', 'integer', 'between:25,100'],
            'element.appearance.shadow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.shadowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
            'element.appearance.glow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.glowColorId' => ['sometimes', 'filled', 'string', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $document */
    private function assertTextDocument(array $document): void
    {
        $length = 0;
        foreach ($document['children'] as $blockIndex => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type !== 'paragraph') {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Text block.']);
            }
            $expected = ['type', 'children'];
            if (array_diff(array_keys($block), $expected) !== [] || array_diff($expected, array_keys($block)) !== []) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Text block structure.']);
            }
            $collections = [$block['children']];
            if (! is_array($collections) || $collections === []) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Text content is required.']);
            }
            foreach ($collections as $runs) {
                if (! is_array($runs) || $runs === []) {
                    throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Text content is required.']);
                }
                foreach ($runs as $run) {
                    if (! is_array($run) || ! array_key_exists('text', $run) || ! is_string($run['text']) || array_diff(array_keys($run), ['text', 'marks', 'colorId']) !== []) {
                        throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Text run.']);
                    }
                    $length += mb_strlen($run['text']);
                    if (array_key_exists('marks', $run)) {
                        $this->assertTextMarks($run['marks'], $blockIndex);
                    }
                    if (array_key_exists('colorId', $run) && (! is_string($run['colorId']) || $run['colorId'] === '')) {
                        throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Text run color must be a non-empty color ID.']);
                    }
                }
            }
        }
        if ($length > 20000) {
            throw ValidationException::withMessages(['element.document' => 'Text cannot exceed 20000 characters.']);
        }
    }

    private function assertTextMarks(mixed $marks, int $blockIndex): void
    {
        if (! is_array($marks) || array_diff(array_keys($marks), ['bold', 'italic', 'underline', 'strikethrough']) !== []) {
            throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Text marks.']);
        }
        foreach (['bold', 'italic', 'underline', 'strikethrough'] as $mark) {
            if (array_key_exists($mark, $marks) && ! is_bool($marks[$mark])) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Text marks must be boolean.']);
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function assertTextFontTuple(array $element): void
    {
        $appearance = $element['appearance'] ?? [];
        $fontId = $appearance['fontFamilyId'] ?? null;
        if (! is_string($fontId)) {
            return;
        }
        $fonts = [...$this->fonts->platformFonts(), ...$this->fonts->classicLegacyFonts(), ...$this->fonts->modernLegacyFonts()];
        $font = collect($fonts)->first(fn ($candidate): bool => $candidate->id === $fontId);
        if ($font === null) {
            throw ValidationException::withMessages(['element.appearance.fontFamilyId' => 'The Text font family is not supported.']);
        }
        if (isset($appearance['fontWeight']) && ! in_array($appearance['fontWeight'], $font->weights, true)) {
            throw ValidationException::withMessages(['element.appearance.fontWeight' => 'The Text font weight is not supported by the selected family.']);
        }
        if (($appearance['italic'] ?? false) === true && ! in_array('italic', $font->styles, true)) {
            throw ValidationException::withMessages(['element.appearance.italic' => 'Italic is not supported by the selected family.']);
        }
    }

    /** @param array<string, mixed> $element */
    private function assertTextFontWeight(array $element): void
    {
        $appearance = $element['appearance'] ?? [];
        $fontId = $appearance['fontFamilyId'] ?? null;
        if (! is_string($fontId)) {
            return;
        }
        $fonts = [...$this->fonts->platformFonts(), ...$this->fonts->classicLegacyFonts(), ...$this->fonts->modernLegacyFonts()];
        $font = collect($fonts)->first(fn ($candidate): bool => $candidate->id === $fontId);
        if ($font === null) {
            throw ValidationException::withMessages(['element.appearance.fontFamilyId' => 'The Text font family is not supported.']);
        }
        if (isset($appearance['fontWeight']) && ! in_array($appearance['fontWeight'], $font->weights, true)) {
            throw ValidationException::withMessages(['element.appearance.fontWeight' => 'The Text font weight is not supported by the selected family.']);
        }
    }

    /** @return array<string, list<string>> */
    private function imageRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,mediaId'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:image'],
            'element.mediaId' => ['required', 'string', 'ulid'],
        ];
    }

    /** @return array<string, list<string>> */
    private function quoteRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,text,attribution'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:quote'],
            'element.text' => ['present', 'string', 'max:5000'],
            'element.attribution' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, list<string>>
     */
    private function ctaRules(array $element): array
    {
        $actionValue = $element['action']['type'] ?? null;
        $action = is_string($actionValue) ? CtaActionType::tryFrom($actionValue) : null;
        if ($action === null) {
            throw ValidationException::withMessages([
                'element.action.type' => $actionValue === null ? 'The CTA action type is required.' : 'The CTA action type is not supported.',
            ]);
        }

        $actionKeys = match ($action) {
            CtaActionType::ScrollToSection => 'type,sectionId',
            CtaActionType::ExternalUrl => 'type,url',
            default => 'type',
        };

        $rules = [
            'element' => ['required', 'array:id,type,label,action'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:cta'],
            'element.label' => ['present', 'string', 'max:255'],
            'element.action' => ['required', "array:{$actionKeys}"],
            'element.action.type' => ['required', 'in:'.$action->value],
        ];

        if ($action === CtaActionType::ScrollToSection) {
            $rules['element.action.sectionId'] = $this->idRules();
        }
        if ($action === CtaActionType::ExternalUrl) {
            $rules['element.action.url'] = ['required', 'string', 'max:2048', 'url:https'];
        }

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function mediaRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,editorName,items,presentation,appearance,isHidden'],
            'element.id' => $this->idRules(), 'element.type' => ['required', 'in:media'],
            'element.items' => ['present', 'array', 'list', 'max:8'], 'element.items.*' => ['required', 'array'],
            'element.items.*.id' => $this->idRules(), 'element.items.*.type' => ['required', 'in:image,video'],
            'element.items.*.mediaId' => ['required_if:element.items.*.type,image', 'string', 'ulid'],
            'element.items.*.alt' => ['sometimes', 'string', 'max:500'], 'element.items.*.decorative' => ['sometimes', 'boolean'],
            'element.items.*.focalPoint' => ['sometimes', 'array:x,y'], 'element.items.*.focalPoint.x' => ['required_with:element.items.*.focalPoint', 'numeric', 'between:0,1'], 'element.items.*.focalPoint.y' => ['required_with:element.items.*.focalPoint', 'numeric', 'between:0,1'],
            'element.items.*.zoom' => ['sometimes', 'numeric', 'between:1,3'],
            'element.items.*.url' => ['required_if:element.items.*.type,video', 'string', 'max:2048', 'url:https', $this->directVideoUrlRule()], 'element.items.*.controls' => ['sometimes', 'boolean'],
            'element.presentation' => ['sometimes', 'array:mode,width,alignment,aspectRatio,fit,carousel,responsive'], 'element.presentation.mode' => ['sometimes', 'in:single,carousel'], 'element.presentation.width' => ['sometimes', 'in:small,medium,large,full'], 'element.presentation.alignment' => ['sometimes', 'in:start,center,end'], 'element.presentation.aspectRatio' => ['sometimes', 'in:natural,square,portrait,landscape,wide'], 'element.presentation.fit' => ['sometimes', 'in:cover,contain'],
            'element.presentation.carousel' => ['sometimes', 'array:autoplay,interval,arrows,dots,loop'], 'element.presentation.carousel.autoplay' => ['sometimes', 'boolean'], 'element.presentation.carousel.interval' => ['sometimes', 'integer', 'between:2000,15000'], 'element.presentation.carousel.arrows' => ['sometimes', 'boolean'], 'element.presentation.carousel.dots' => ['sometimes', 'boolean'], 'element.presentation.carousel.loop' => ['sometimes', 'boolean'],
            'element.presentation.responsive' => ['sometimes', 'array:tablet,mobile'], 'element.presentation.responsive.*' => ['sometimes', 'array:mode,width,aspectRatio'], 'element.presentation.responsive.*.mode' => ['sometimes', 'in:single,carousel'], 'element.presentation.responsive.*.width' => ['sometimes', 'in:small,medium,large,full'], 'element.presentation.responsive.*.aspectRatio' => ['sometimes', 'in:natural,square,portrait,landscape,wide'],
            'element.appearance' => ['sometimes', 'array:corners,frame,shadow'], 'element.appearance.corners' => ['sometimes', 'in:square,soft,rounded,pill'], 'element.appearance.frame' => ['sometimes', 'in:none,line,mat'], 'element.appearance.shadow' => ['sometimes', 'in:none,soft,medium,strong'],
        ];
    }

    private function directVideoUrlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $hostname = strtolower(rtrim((string) parse_url($value, PHP_URL_HOST), '.'));
            foreach (['youtube.com', 'youtu.be', 'vimeo.com'] as $unsupportedHost) {
                if ($hostname === $unsupportedHost || str_ends_with($hostname, '.'.$unsupportedHost)) {
                    $fail("Direct video file required. YouTube and Vimeo links aren't supported.");

                    return;
                }
            }
        };
    }

    private function assertMediaItemShapes(mixed $items): void
    {
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $allowed = ($item['type'] ?? null) === 'video'
                ? ['id', 'type', 'url', 'controls']
                : ['id', 'type', 'mediaId', 'alt', 'decorative', 'focalPoint', 'zoom'];
            if (array_diff(array_keys($item), $allowed) !== []) {
                throw ValidationException::withMessages(["element.items.{$index}" => 'The Media item contains unsupported properties.']);
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function assertMediaJsonTypes(array $element): void
    {
        foreach ($element['items'] ?? [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['decorative', 'controls'] as $field) {
                if (array_key_exists($field, $item) && ! is_bool($item[$field])) {
                    throw ValidationException::withMessages(["element.items.{$index}.{$field}" => 'The field must be a JSON boolean.']);
                }
            }
            foreach (['zoom'] as $field) {
                if (array_key_exists($field, $item) && ! is_int($item[$field]) && ! is_float($item[$field])) {
                    throw ValidationException::withMessages(["element.items.{$index}.{$field}" => 'The field must be a JSON number.']);
                }
            }
            foreach (['x', 'y'] as $coordinate) {
                if (isset($item['focalPoint']) && is_array($item['focalPoint']) && array_key_exists($coordinate, $item['focalPoint']) && ! is_int($item['focalPoint'][$coordinate]) && ! is_float($item['focalPoint'][$coordinate])) {
                    throw ValidationException::withMessages(["element.items.{$index}.focalPoint.{$coordinate}" => 'The field must be a JSON number.']);
                }
            }
        }
        $carousel = $element['presentation']['carousel'] ?? null;
        if (! is_array($carousel)) {
            return;
        }
        foreach (['autoplay', 'arrows', 'dots', 'loop'] as $field) {
            if (array_key_exists($field, $carousel) && ! is_bool($carousel[$field])) {
                throw ValidationException::withMessages(["element.presentation.carousel.{$field}" => 'The field must be a JSON boolean.']);
            }
        }
        if (array_key_exists('interval', $carousel) && ! is_int($carousel['interval'])) {
            throw ValidationException::withMessages(['element.presentation.carousel.interval' => 'The field must be a JSON integer.']);
        }
    }

    /** @return list<string> */
    private function idRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/^\s*$/'];
    }
}
