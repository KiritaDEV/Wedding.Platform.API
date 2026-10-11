<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveWebsiteDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Event/project authorization and revision check are in WebsiteDraftBoundary.
    }

    public function rules(): array
    {
        return [
            'sectionIds' => ['sometimes', 'required', 'array', 'list'],
            'sectionIds.*' => ['required', 'string', 'distinct'],
            'sections' => ['sometimes', 'required', 'array', 'list'],
            'sections.*' => ['required', 'array:id,content,appearance,isEnabled'],
            'sections.*.id' => ['required', 'string', 'distinct'],
            'sections.*.content' => ['sometimes', 'required', 'array'],
            'sections.*.appearance' => ['sometimes', 'required', 'array'],
            'sections.*.isEnabled' => ['sometimes', 'required', 'boolean'],
            'designSettings' => ['sometimes', 'required', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), ['sectionIds', 'sections', 'designSettings']) === []) {
                $validator->errors()->add('draft', 'Provide at least one draft change.');
            }
            foreach (array_diff(array_keys($this->all()), ['sectionIds', 'sections', 'designSettings']) as $key) {
                $validator->errors()->add($key, 'This draft field cannot be saved here.');
            }
            $sections = $this->input('sections', []);
            if (! is_array($sections)) {
                return;
            }
            $raw = json_decode($this->getContent());
            foreach (['sections', 'sectionIds'] as $list) {
                if ($raw instanceof \stdClass && property_exists($raw, $list) && ! is_array($raw->{$list})) {
                    $validator->errors()->add($list, 'This field must be a JSON list.');
                }
            }
            if (! is_array($raw->sections ?? [])) {
                return;
            }
            foreach ($sections as $index => $section) {
                if (! is_array($section) || ! array_key_exists('content', $section)) {
                    continue;
                }
                // Reuse the original JSON shape checks, including nested Media/Divider objects.
                // Preserve {} versus [] from the original aggregate JSON.
                $contentRequest = UpdateWebsiteSectionContentRequest::create('/', 'PUT', [], [], [], [], json_encode(['content' => $raw->sections[$index]->content ?? null], JSON_THROW_ON_ERROR));
                $contentValidator = app('validator')->make(['content' => $section['content']], $contentRequest->rules());
                foreach ($contentRequest->after() as $check) {
                    $check($contentValidator);
                }
                foreach ($contentValidator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("sections.{$index}.{$field}", $message);
                    }
                }
            }
        }];
    }
}
