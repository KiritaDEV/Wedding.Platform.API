<?php

namespace App\Http\Requests;

use App\Website\WebsiteSectionEditorNames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RenameWebsiteSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'editorName' => ['required', 'string', 'max:'.WebsiteSectionEditorNames::MAX_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['editorName']) !== []) {
                $validator->errors()->add('request', 'The request contains unsupported properties.');
            }
        }];
    }
}
