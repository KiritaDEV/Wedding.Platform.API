<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRsvpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'isOpen' => ['required', 'boolean'],
            'deadline' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function settings(): array
    {
        return [
            'rsvp_is_open' => $this->validated('isOpen'),
            'rsvp_deadline' => $this->validated('deadline'),
        ];
    }
}
