<?php

use App\Exceptions\MediaAssetInUse;
use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->statefulApi();

        // API-only backend: unauthenticated requests have no Laravel
        // web-login destination.
        $middleware->redirectGuestsTo(static fn () => null);

        // Rich Text runs are a token stream: empty runs and whitespace at the
        // edge of a marked run are both meaningful. Laravel's default request
        // normalizers would otherwise change `{ text: "" }` to null and trim
        // `{ text: "word " }` to `{ text: "word" }` before validation.
        $isWebsiteSectionUpdate = static fn (Request $request): bool => $request->method() === 'PUT'
            && preg_match('#^api/events/[^/]+/(?:website|websites/[^/]+)/sections/[^/]+$#', $request->path()) === 1;

        $middleware->trimStrings(except: [$isWebsiteSectionUpdate]);

        $middleware->convertEmptyStringsToNull(except: [
            $isWebsiteSectionUpdate,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, \Throwable $exception): bool =>
                $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (MediaAssetInUse $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'code' => MediaAssetInUse::CODE,
                'message' => MediaAssetInUse::PUBLIC_MESSAGE,
                'usage' => $exception->usage->toArray(),
            ], 409);
        });

        $exceptions->render(function (UnsupportedWebsiteSchemaVersion $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'code' => UnsupportedWebsiteSchemaVersion::CODE,
                'message' => UnsupportedWebsiteSchemaVersion::PUBLIC_MESSAGE,
            ], 409);
        });
    })->create();