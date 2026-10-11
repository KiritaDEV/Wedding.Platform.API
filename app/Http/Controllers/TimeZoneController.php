<?php

namespace App\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\JsonResponse;

class TimeZoneController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (string $identifier): array => ['id' => $identifier, 'displayName' => $identifier],
            DateTimeZone::listIdentifiers(),
        )])->header('Cache-Control', 'private, max-age=86400');
    }
}
