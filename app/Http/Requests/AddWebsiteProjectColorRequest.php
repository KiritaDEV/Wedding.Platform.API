<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddWebsiteProjectColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'id' => ['prohibited'],
            'customColors' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['id', 'customColors'] as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, "The {$field} field is prohibited.");
                }
            }
        }];
    }
}
