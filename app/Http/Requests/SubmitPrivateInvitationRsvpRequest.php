<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPrivateInvitationRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
            'responses' => ['required', 'array', 'min:1'],
            'responses.*' => ['required', 'array:guestId,response'],
            'responses.*.guestId' => ['required', 'string', 'ulid', 'distinct:strict'],
            'responses.*.response' => ['required', 'string', Rule::in(['attending', 'declined'])],
        ];
    }
}
