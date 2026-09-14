<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWebsiteSectionContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['content' => ['required', 'array']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            // Associative decoding loses the distinction between {} and [].
            // Check the original JSON before validating object-shaped element state.
            $body = json_decode($this->getContent());
            $visit = function (mixed $element, string $path) use (&$visit, $validator): void {
                if (! $element instanceof \stdClass) {
                    return;
                }
                if (($element->type ?? null) === 'divider' && property_exists($element, 'appearance') && ! $element->appearance instanceof \stdClass) {
                    $validator->errors()->add($path.'.appearance', 'Divider appearance must be a JSON object.');
                }
                if (($element->type ?? null) === 'media') {
                    $requireObject = function (object $owner, string $property, string $propertyPath) use ($validator): void {
                        if (property_exists($owner, $property) && ! $owner->{$property} instanceof \stdClass) {
                            $validator->errors()->add($propertyPath, 'The field must be a JSON object.');
                        }
                    };
                    $requireObject($element, 'presentation', $path.'.presentation');
                    $requireObject($element, 'appearance', $path.'.appearance');
                    if (($element->presentation ?? null) instanceof \stdClass) {
                        $requireObject($element->presentation, 'carousel', $path.'.presentation.carousel');
                        $requireObject($element->presentation, 'responsive', $path.'.presentation.responsive');
                        if (($element->presentation->responsive ?? null) instanceof \stdClass) {
                            $requireObject($element->presentation->responsive, 'tablet', $path.'.presentation.responsive.tablet');
                            $requireObject($element->presentation->responsive, 'mobile', $path.'.presentation.responsive.mobile');
                        }
                    }
                    if (is_array($element->items ?? null)) {
                        foreach ($element->items as $index => $item) {
                            if ($item instanceof \stdClass) {
                                $requireObject($item, 'focalPoint', $path.'.items.'.$index.'.focalPoint');
                            }
                        }
                    }
                }
                if (($element->type ?? null) === 'compositionGroup' && is_array($element->children ?? null)) {
                    foreach ($element->children as $index => $child) {
                        $visit($child, $path.'.children.'.$index);
                    }
                }
            };
            $compositions = $body->content->compositions ?? null;
            if ($compositions instanceof \stdClass) {
                $branches = ['shared' => $compositions->shared ?? null];
                foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
                    if (($compositions->custom ?? null) instanceof \stdClass && property_exists($compositions->custom, $viewport)) {
                        $branches[$viewport] = $compositions->custom->{$viewport};
                    }
                }
                foreach ($branches as $branch => $composition) {
                    $elements = $composition->childFlow->elements ?? null;
                    if (! is_array($elements)) {
                        continue;
                    }
                    foreach ($elements as $index => $element) {
                        $visit($element, 'content.compositions.'.$branch.'.childFlow.elements.'.$index);
                    }
                }
            }
        }];
    }
}
