<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreEventCollaboratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageCollaborators', Event::query()->findOrFail($this->route('event')));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['email']) as $field) {
                $validator->errors()->add($field, 'Only an email address may be supplied.');
            }
        }];
    }
}
