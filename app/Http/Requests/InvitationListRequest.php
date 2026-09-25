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
            'relationships' => ['sometimes', 'array'],
            'relationships.*' => ['distinct', Rule::enum(GuestRelationship::class)],
            'sides' => ['sometimes', 'array'],
            'sides.*' => ['distinct', Rule::enum(GuestSide::class)],
            'roleIds' => ['sometimes', 'array'],
            'roleIds.*' => ['distinct', 'string', 'ulid'],
            'rsvpStatuses' => ['sometimes', 'array'],
            'rsvpStatuses.*' => ['distinct', Rule::in(['pending', 'partial', 'complete'])],
            'guestResponses' => ['sometimes', 'array'],
            'guestResponses.*' => ['distinct', Rule::in(['pending', 'attending', 'declined'])],
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
            'relationships' => $this->validated('relationships', []),
            'sides' => $this->validated('sides', []),
            'role_ids' => $this->validated('roleIds', []),
            'rsvp_statuses' => $this->validated('rsvpStatuses', []),
            'guest_responses' => $this->validated('guestResponses', []),
            'sort' => $this->validated('sort', 'recently_added'),
        ];
    }
}
