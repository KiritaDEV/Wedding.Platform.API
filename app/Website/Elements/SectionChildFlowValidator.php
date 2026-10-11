<?php

namespace App\Website\Elements;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SectionChildFlowValidator
{
    public function __construct(private readonly WebsiteElementValidator $elements) {}

    /**
     * @param  array<string, mixed>  $flow
     * @param  list<string>  $allowedTypes
     * @return array{elements: list<array<string, mixed>>, order: list<array<string, string>>}
     */
    public function validate(array $flow, array $allowedTypes, bool $requiresSpecializedContent = true): array
    {
        $validated = Validator::make(['flow' => $flow], [
            'flow' => ['required', 'array:elements,order'],
            'flow.elements' => ['present', 'array', 'list', 'max:20'],
            'flow.elements.*' => ['required', 'array'],
            'flow.order' => ['present', 'array', 'list', 'max:21'],
            'flow.order.*' => ['required', 'array:kind,key,id'],
            'flow.order.*.kind' => ['required', 'in:specialized,element'],
            'flow.order.*.key' => ['sometimes', 'string'],
            'flow.order.*.id' => ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
        ])->validate()['flow'];

        try {
            $validated['elements'] = $this->elements->validateTree($validated['elements']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['content.childFlow.elements' => $exception->getMessage()]);
        }

        foreach ($validated['elements'] as $index => $element) {
            if (! in_array($element['type'], $allowedTypes, true)) {
                throw ValidationException::withMessages([
                    "content.childFlow.elements.{$index}.type" => "Element type [{$element['type']}] is not allowed in this Section.",
                ]);
            }
        }

        $specializedCount = 0;
        $references = [];
        foreach ($validated['order'] as $index => $reference) {
            if ($reference['kind'] === 'specialized') {
                if (count($reference) !== 2 || ! isset($reference['key']) || $reference['key'] !== 'content') {
                    throw ValidationException::withMessages(["content.childFlow.order.{$index}" => 'The specialized reference must target content.']);
                }
                $specializedCount++;
            } else {
                if (count($reference) !== 2 || ! isset($reference['id'])) {
                    throw ValidationException::withMessages(["content.childFlow.order.{$index}" => 'An element reference must contain only kind and id.']);
                }
                $references[] = $reference['id'];
            }
        }

        $ids = array_column($validated['elements'], 'id');
        $expectedSpecializedCount = $requiresSpecializedContent ? 1 : 0;
        if ($specializedCount !== $expectedSpecializedCount) {
            $message = $requiresSpecializedContent
                ? 'Child flow must contain exactly one specialized content reference.'
                : 'Generic-only child flow cannot contain a specialized content reference.';
            throw ValidationException::withMessages(['content.childFlow.order' => $message]);
        }
        if (count($references) !== count(array_unique($references, SORT_STRING))) {
            throw ValidationException::withMessages(['content.childFlow.order' => 'Child flow element references must be unique.']);
        }
        if (count($references) !== count($ids) || array_diff($references, $ids) !== [] || array_diff($ids, $references) !== []) {
            throw ValidationException::withMessages(['content.childFlow.order' => 'Child flow order must reference every element exactly once.']);
        }

        return $validated;
    }
}
