<?php

namespace App\Http\Requests;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class InvitationStateRequest extends FormRequest
{
    abstract protected function allowsExistingGuests(): bool;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customRoles' => ['sometimes', 'array', 'list'],
            'customRoles.*' => ['required', 'array:clientKey,name'],
            'customRoles.*.clientKey' => ['required', 'string', 'max:100', 'distinct'],
            'customRoles.*.name' => ['required', 'string', 'max:255'],
            'guests' => ['required', 'array', 'list', 'min:1'],
            'guests.*' => ['required', 'array:'.($this->allowsExistingGuests() ? 'id,' : '').'firstName,lastName,relationship,side,weddingRoleIds,customWeddingRoleKeys'],
            'guests.*.id' => [$this->allowsExistingGuests() ? 'sometimes' : 'prohibited', 'string', 'ulid', 'distinct'],
            'guests.*.firstName' => ['required', 'string', 'max:255'],
            'guests.*.lastName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guests.*.relationship' => ['sometimes', Rule::enum(GuestRelationship::class)],
            'guests.*.side' => ['sometimes', Rule::enum(GuestSide::class)],
            'guests.*.weddingRoleIds' => ['sometimes', 'array', 'list'],
            'guests.*.weddingRoleIds.*' => ['required', 'string', 'ulid'],
            'guests.*.customWeddingRoleKeys' => ['sometimes', 'array', 'list'],
            'guests.*.customWeddingRoleKeys.*' => ['required', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $defined = collect($this->input('customRoles', []))->pluck('clientKey');
            $referenced = collect($this->input('guests', []))->flatMap(fn ($guest) => $guest['customWeddingRoleKeys'] ?? []);
            if ($referenced->diff($defined)->isNotEmpty()) {
                $validator->errors()->add('guests', 'A Guest references an undefined draft Wedding Role.');
            }
        }];
    }

    /** @return array{custom_name: ?string, custom_roles: array, guests: array} */
    public function invitationState(): array
    {
        return [
            'custom_name' => $this->validated('customName'),
            'custom_roles' => collect($this->validated('customRoles', []))->map(fn ($role) => [
                'client_key' => $role['clientKey'], 'name' => $role['name'],
            ])->all(),
            'guests' => collect($this->validated('guests'))->map(fn ($guest) => [
                ...(isset($guest['id']) ? ['id' => $guest['id']] : []),
                'first_name' => $guest['firstName'],
                'last_name' => $guest['lastName'] ?? null,
                'relationship' => $guest['relationship'] ?? GuestRelationship::GuestOther->value,
                'side' => $guest['side'] ?? GuestSide::Unspecified->value,
                'wedding_role_ids' => $guest['weddingRoleIds'] ?? [],
                'custom_wedding_role_keys' => $guest['customWeddingRoleKeys'] ?? [],
            ])->all(),
        ];
    }
}
