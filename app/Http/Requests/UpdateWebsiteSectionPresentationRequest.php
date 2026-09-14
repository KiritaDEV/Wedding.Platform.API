<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteSectionPresentationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');
        if (! is_array($content)) {
            return;
        }

        foreach (['shared', 'desktop', 'tablet', 'mobile'] as $branch) {
            $composition = $branch === 'shared'
                ? data_get($content, 'compositions.shared')
                : data_get($content, "compositions.custom.{$branch}");
            if (! is_array($composition)) {
                continue;
            }
            $elements = $composition['childFlow']['elements'] ?? null;
            if (! is_array($elements)) {
                continue;
            }
            $composition['childFlow']['elements'] = array_map($this->canonicalizeElement(...), $elements);
            if ($branch === 'shared') {
                data_set($content, 'compositions.shared', $composition);
            } else {
                data_set($content, "compositions.custom.{$branch}", $composition);
            }
        }

        $this->merge(['content' => $content]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['content' => ['required', 'array'], 'appearance' => ['required', 'array']];
    }

    /** @param array<string, mixed> $element @return array<string, mixed> */
    private function canonicalizeElement(array $element): array
    {
        if (($element['type'] ?? null) === 'text' && is_array($element['document']['children'] ?? null)) {
            foreach ($element['document']['children'] as &$block) {
                if (! is_array($block) || ! is_array($block['children'] ?? null)) {
                    continue;
                }
                foreach ($block['children'] as &$run) {
                    if (! is_array($run) || ! is_string($run['text'] ?? null)) {
                        continue;
                    }
                    $canonical = ['text' => $run['text']];
                    if (is_array($run['marks'] ?? null)) {
                        $marks = array_filter(
                            array_intersect_key($run['marks'], array_flip(['bold', 'italic', 'underline', 'strikethrough'])),
                            fn (mixed $value): bool => $value === true,
                        );
                        if ($marks !== []) {
                            $canonical['marks'] = $marks;
                        }
                    }
                    if (is_string($run['colorId'] ?? null) && $run['colorId'] !== '') {
                        $canonical['colorId'] = $run['colorId'];
                    }
                    $run = $canonical;
                }
                unset($run);
            }
            unset($block);
        }
        if (($element['type'] ?? null) === 'compositionGroup' && is_array($element['children'] ?? null)) {
            $element['children'] = array_map($this->canonicalizeElement(...), $element['children']);
        }

        return $element;
    }
}
