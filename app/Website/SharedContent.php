<?php

namespace App\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Section-owned, opt-in semantic definitions. Only Schedule is supported. */
final class SharedContent
{
    public function validate(array $content): array
    {
        $definitions = $content['sharedContentDefinitions'] ?? [];
        Validator::make(['definitions' => $definitions], [
            'definitions' => ['array', 'list'],
            'definitions.*' => ['required', 'array:id,type,content'],
            'definitions.*.id' => ['required', 'string', 'max:255', 'regex:/^\S+$/u'],
            'definitions.*.type' => ['required', 'in:schedule'],
            'definitions.*.content' => ['required', 'array:days'],
            'definitions.*.content.days' => ['present', 'array', 'list', 'max:20'],
        ])->validate();
        $byId = [];
        foreach ($definitions as $index => $definition) {
            if (isset($byId[$definition['id']])) {
                $this->fail('Duplicate shared content identity.');
            }
            $definition['content'] = app(ScheduleContent::class)->validate($definition['content']);
            $definitions[$index] = $definition;
            $byId[$definition['id']] = $definition;
        }
        $used = [];
        foreach ($this->elements($content) as $element) {
            if (! array_key_exists('contentId', $element)) {
                continue;
            }
            $definition = $byId[$element['contentId']] ?? null;
            if ($definition === null || $definition['type'] !== $element['type']) {
                $this->fail('Missing or incompatible Section-owned shared content.');
            }
            $used[$definition['id']] = true;
        }
        if (count($used) !== count($definitions)) {
            $this->fail('Unreferenced definitions must be removed with the placement edit.');
        }
        if (array_key_exists('sharedContentDefinitions', $content)) {
            $content['sharedContentDefinitions'] = $definitions;
        }

        return $content;
    }

    public function elements(array $content): array
    {
        $result = [];
        $visit = function (array $elements) use (&$visit, &$result): void {
            foreach ($elements as $element) {
                $result[] = $element;
                if (($element['type'] ?? null) === 'compositionGroup') {
                    $visit($element['children']);
                }
            }
        };
        foreach ([$content['compositions']['shared'] ?? [], ...array_values($content['compositions']['custom'] ?? [])] as $branch) {
            $visit($branch['childFlow']['elements'] ?? []);
        }

        return $result;
    }

    public function resolve(array $element, array $definitions): array
    {
        if (! isset($element['contentId'])) {
            return $element;
        }
        foreach ($definitions as $definition) {
            if ($definition['id'] === $element['contentId'] && $definition['type'] === $element['type']) {
                return [...$element, ...$definition['content']];
            }
        }
        $this->fail('Missing or incompatible shared content.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content.sharedContentDefinitions' => $message]);
    }
}
