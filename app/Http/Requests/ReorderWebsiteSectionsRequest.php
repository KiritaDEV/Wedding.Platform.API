<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderWebsiteSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sectionIds' => ['required', 'array', 'min:1'],
            'sectionIds.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }
}
