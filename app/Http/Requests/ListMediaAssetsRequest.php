<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMediaAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['jpeg', 'png', 'webp'])],
            'orientation' => ['sometimes', Rule::in(['landscape', 'portrait', 'square'])],
            'uploaded' => ['sometimes', Rule::in(['today', '7d', '30d'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
