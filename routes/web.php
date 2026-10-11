<?php

use App\Http\Controllers\PrivateInvitationController;
use Illuminate\Support\Facades\Route;

Route::post('/api/private-invitations/open', [PrivateInvitationController::class, 'open'])
    ->middleware('throttle:10,1');
Route::post('/api/private-invitations/rsvp', [PrivateInvitationController::class, 'rsvp'])
    ->middleware('throttle:10,1');
Route::post('/api/private-invitations/access-requests', [PrivateInvitationController::class, 'requestAccess'])
    ->middleware('throttle:10,1');
Route::post('/api/private-invitations/access-requests/approve', [PrivateInvitationController::class, 'approveAccess'])
    ->middleware('throttle:10,1');
Route::post('/api/private-invitations/access-requests/reject', [PrivateInvitationController::class, 'rejectAccess'])
    ->middleware('throttle:10,1');

Route::get('/', function () {
    return view('welcome');
});
