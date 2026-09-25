<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrivateInvitationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
            'handoffOnly' => ['sometimes', 'boolean'],
        ];
    }
}
