<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Website;
use App\Website\Publishing\WebsitePublicationCoordinator;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Serializes draft reads/writes with publishing and media reference mutations. */
final class WebsiteDraftBoundary
{
    public function handle(Request $request, Closure $next): mixed
    {
        $event = Event::query()->findOrFail($request->route('event'));
        $readOnly = $request->isMethod('GET') || $request->isMethod('HEAD');
        Gate::authorize($readOnly ? 'view' : 'update', $event);
        $projectId = $request->route('website');
        if ($projectId === null) {
            $projects = $event->websiteProjects()->limit(2)->get();
            abort_if($projects->count() > 1, 409, 'This Event has multiple Website Projects. Use a project-specific route.');
            $projectId = $projects->first()?->id;
        }
        $project = $event->websiteProjects()->findOrFail($projectId);
        $coordinator = app(WebsitePublicationCoordinator::class);

        return $coordinator->forProject($project, function (Event $event, Website $locked) use ($request, $next, $coordinator, $readOnly): mixed {
            if ($readOnly) {
                return $next($request);
            }
            $expected = $request->header('X-Website-Draft-Revision');
            if (! is_string($expected) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $expected) !== 1) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Load the draft and supply X-Website-Draft-Revision before saving.',
                    'code' => 'draft_revision_required',
                ], 428));
            }
            if (! hash_equals($locked->draft_revision, $expected)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Another tab or session saved newer changes. Your local edits have been kept. Reloading discards unsaved edits.',
                    'code' => 'stale_website_draft',
                    'currentDraftRevision' => $locked->draft_revision,
                ], 409));
            }

            return $coordinator->forDraftMutation($locked, function () use ($request, $next): mixed {
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }

                return $response;
            });
        });
    }
}
