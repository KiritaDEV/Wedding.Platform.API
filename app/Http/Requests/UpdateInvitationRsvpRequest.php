<?php

namespace App\Http\Requests;

use App\Enums\RsvpResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvitationRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responses' => ['required', 'array', 'min:1'],
            'responses.*' => ['required', 'array:guestId,response'],
            'responses.*.guestId' => ['required', 'string', 'ulid', 'distinct'],
            'responses.*.response' => ['present', 'nullable', Rule::enum(RsvpResponse::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function desiredState(): array
    {
        return [
            'responses' => collect($this->validated('responses'))->map(fn (array $item): array => [
                'guest_id' => $item['guestId'], 'response' => $item['response'],
            ])->all(),
            'note' => ($note = trim((string) $this->validated('note', ''))) === '' ? null : $note,
        ];
    }
}
