<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destinationInvitationId' => ['required', 'string', 'ulid'],
        ];
    }
}
