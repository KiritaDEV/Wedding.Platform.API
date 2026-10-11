<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateInvitationController;
use App\Http\Controllers\PublicEventSiteController;
use App\Http\Controllers\TimeZoneController;
use App\Http\Controllers\WebsiteDraftController;

Route::get('/public/events/{slug}/site', [PublicEventSiteController::class, 'show']);
Route::get('/public/events/{slug}/media/{asset}/web', [PublicEventSiteController::class, 'media'])
    ->name('public.events.media.web');
use App\Http\Controllers\WeddingRoleController;
use Illuminate\Support\Facades\Route;

Route::post('/private-invitations/context', [PrivateInvitationController::class, 'context'])
    ->middleware('throttle:30,1');
Route::post('/private-invitations/site', [PrivateInvitationController::class, 'site'])
    ->middleware('throttle:30,1');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications/summary', [NotificationController::class, 'summary']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::put('/events/{event}/timing', [EventController::class, 'updateTiming']);
    Route::put('/events/{event}/rsvp-settings', [EventController::class, 'updateRsvpSettings']);
    Route::get('/time-zones', [TimeZoneController::class, 'index']);

    Route::get('/events/{event}/invitations', [InvitationController::class, 'index']);
    Route::get('/events/{event}/invitation-options', [InvitationController::class, 'options']);
    Route::get('/events/{event}/wedding-roles', [WeddingRoleController::class, 'index']);
    Route::post('/events/{event}/invitations', [InvitationController::class, 'store']);
    Route::get('/events/{event}/invitations/{invitation}', [InvitationController::class, 'show']);
    Route::get('/events/{event}/invitations/{invitation}/access-audit', [InvitationController::class, 'accessAudit']);
    Route::put('/events/{event}/invitations/{invitation}', [InvitationController::class, 'update']);
    Route::put('/events/{event}/invitations/{invitation}/rsvp', [InvitationController::class, 'updateRsvp']);
    Route::get('/events/{event}/invitations/{invitation}/rsvp-history', [InvitationController::class, 'rsvpHistory']);
    Route::post('/events/{event}/invitations/{invitation}/activate', [InvitationController::class, 'activate']);
    Route::post('/events/{event}/invitations/{invitation}/deactivate', [InvitationController::class, 'deactivate']);
    Route::post('/events/{event}/invitations/{sourceInvitation}/guests/{guest}/move', [InvitationController::class, 'move']);
    Route::delete('/events/{event}/invitations/{invitation}', [InvitationController::class, 'destroy']);
    Route::delete('/events/{event}/invitations/{invitation}/trusted-access', [InvitationController::class, 'resetTrustedAccess']);
    Route::post('/events/{event}/invitations/{invitation}/private-link/rotate', [InvitationController::class, 'rotatePrivateLink']);

    Route::get('/events/{event}/media', [MediaAssetController::class, 'index']);
    Route::post('/events/{event}/media', [MediaAssetController::class, 'store']);
    Route::get('/events/{event}/media/{asset}', [MediaAssetController::class, 'show']);
    Route::delete('/events/{event}/media/{asset}', [MediaAssetController::class, 'destroy']);
    Route::get('/events/{event}/media/{asset}/variants/{variant}', [MediaAssetController::class, 'variant'])
        ->name('events.media.variants.show');

    Route::get('/events/{event}/website', [WebsiteDraftController::class, 'show']);
    Route::post('/events/{event}/website', [WebsiteDraftController::class, 'store']);
    Route::get('/events/{event}/website-templates', [WebsiteDraftController::class, 'creationTemplates']);
    Route::put('/events/{event}/website/design', [WebsiteDraftController::class, 'updateDesign']);
    Route::put('/events/{event}/website/sections/order', [WebsiteDraftController::class, 'reorder']);
    Route::post('/events/{event}/website/sections', [WebsiteDraftController::class, 'createSection']);
    Route::delete('/events/{event}/website/sections/{section}', [WebsiteDraftController::class, 'deleteSection']);
    Route::post('/events/{event}/website/sections/{section}/duplicate', [WebsiteDraftController::class, 'duplicateSection']);
    Route::put('/events/{event}/website/sections/{section}/editor-name', [WebsiteDraftController::class, 'renameSection']);
    Route::put('/events/{event}/website/sections/{section}/enabled', [WebsiteDraftController::class, 'updateSectionEnabled']);
    Route::put('/events/{event}/website/sections/{section}/appearance', [WebsiteDraftController::class, 'updateSectionAppearance']);
    Route::put('/events/{event}/website/sections/{section}/design-defaults', [WebsiteDraftController::class, 'updateSectionDesignDefaults']);
    Route::put('/events/{event}/website/sections/{section}', [WebsiteDraftController::class, 'updateSection']);

    Route::get('/events/{event}/websites', [WebsiteDraftController::class, 'projects']);
    Route::post('/events/{event}/websites', [WebsiteDraftController::class, 'storeProject']);
    Route::post('/events/{event}/websites/{website}/publish', [WebsiteDraftController::class, 'publish']);
    Route::delete('/events/{event}/published-website', [WebsiteDraftController::class, 'unpublish']);
    Route::get('/events/{event}/websites/{website}', [WebsiteDraftController::class, 'showProject']);
    Route::post('/events/{event}/websites/{website}/colors', [WebsiteDraftController::class, 'addProjectColor']);
    Route::put('/events/{event}/websites/{website}/design', [WebsiteDraftController::class, 'updateProjectDesign']);
    Route::put('/events/{event}/websites/{website}/sections/order', [WebsiteDraftController::class, 'reorderProjectSections']);
    Route::post('/events/{event}/websites/{website}/sections', [WebsiteDraftController::class, 'createProjectSection']);
    Route::delete('/events/{event}/websites/{website}/sections/{section}', [WebsiteDraftController::class, 'deleteProjectSection']);
    Route::post('/events/{event}/websites/{website}/sections/{section}/duplicate', [WebsiteDraftController::class, 'duplicateProjectSection']);
    Route::put('/events/{event}/websites/{website}/sections/{section}/editor-name', [WebsiteDraftController::class, 'renameProjectSection']);
    Route::put('/events/{event}/websites/{website}/sections/{section}/enabled', [WebsiteDraftController::class, 'updateProjectSectionEnabled']);
    Route::put('/events/{event}/websites/{website}/sections/{section}/appearance', [WebsiteDraftController::class, 'updateProjectSectionAppearance']);
    Route::put('/events/{event}/websites/{website}/sections/{section}/presentation', [WebsiteDraftController::class, 'updateProjectSectionPresentation']);
    Route::put('/events/{event}/websites/{website}/sections/{section}/design-defaults', [WebsiteDraftController::class, 'updateProjectSectionDesignDefaults']);
    Route::put('/events/{event}/websites/{website}/sections/{section}', [WebsiteDraftController::class, 'updateProjectSection']);
});
