<?php

namespace App\Http\Requests;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:255'],
            'lifecycle' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'relationship' => ['sometimes', Rule::enum(GuestRelationship::class)],
            'side' => ['sometimes', Rule::enum(GuestSide::class)],
            'weddingRoleId' => ['sometimes', 'string', 'ulid'],
            'rsvp' => ['sometimes', Rule::in(['pending', 'attending', 'declined'])],
            'sort' => ['sometimes', Rule::in([
                'recently_added', 'invitation_asc', 'invitation_desc',
                'last_response_desc', 'last_response_asc',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        return [
            'q' => trim((string) $this->validated('q', '')),
            'lifecycle' => $this->validated('lifecycle', 'all'),
            'relationship' => $this->validated('relationship'),
            'side' => $this->validated('side'),
            'wedding_role_id' => $this->validated('weddingRoleId'),
            'rsvp' => $this->validated('rsvp'),
            'sort' => $this->validated('sort', 'recently_added'),
        ];
    }
}
