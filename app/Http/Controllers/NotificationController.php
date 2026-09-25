<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $query = UserNotification::query()->where('recipient_user_id', $request->user()->id);

        return response()->json(['data' => [
            'unreadCount' => (clone $query)->whereNull('read_at')->count(),
            'latestNotificationId' => (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->value('id'),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $notifications = UserNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate(25);

        return response()->json([
            'data' => UserNotificationResource::collection($notifications->items())->resolve($request),
            'meta' => ['nextCursor' => $notifications->nextCursor()?->encode()],
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $model = UserNotification::query()
            ->where('recipient_user_id', $request->user()->id)->findOrFail($notification);
        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => (new UserNotificationResource($model))->resolve($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = UserNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
