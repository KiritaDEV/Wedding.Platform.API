<?php

namespace App\Http\Controllers;

use App\Http\Resources\WeddingRoleResource;
use App\Invitations\BuiltinWeddingRoles;
use App\Models\Event;
use App\Models\WeddingRole;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WeddingRoleController extends Controller
{
    public function index(string $event): AnonymousResourceCollection
    {
        $eventModel = Event::query()->findOrFail($event);
        Gate::authorize('view', $eventModel);

        $builtInIds = collect(BuiltinWeddingRoles::all())->pluck('id')->all();
        $builtIns = WeddingRole::query()->whereIn('id', $builtInIds)->get()->sortBy(
            fn (WeddingRole $role): int => array_search($role->id, $builtInIds, true),
        );
        $custom = $eventModel->customWeddingRoles()->orderBy('normalized_name')->orderBy('id')->get();

        return WeddingRoleResource::collection($builtIns->concat($custom)->values());
    }
}
