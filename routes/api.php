<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\TimeZoneController;
use App\Http\Controllers\WebsiteDraftController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::put('/events/{event}/timing', [EventController::class, 'updateTiming']);
    Route::get('/time-zones', [TimeZoneController::class, 'index']);

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
