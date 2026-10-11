<?php

namespace App\Http\Controllers;

use App\Actions\Invitations\OpenPrivateInvitation;
use App\Actions\Invitations\ReadPrivateInvitationSite;
use App\Actions\Invitations\RequestInvitationAccessTransfer;
use App\Actions\Invitations\ResolveInvitationAccessTransfer;
use App\Actions\Invitations\ResolveInvitationTrustContext;
use App\Actions\Invitations\SubmitPrivateInvitationRsvp;
use App\Http\Requests\PrivateInvitationTokenRequest;
use App\Http\Requests\SubmitPrivateInvitationRsvpRequest;
use App\Invitations\InvitationTrustCookie;
use Illuminate\Http\JsonResponse;

class PrivateInvitationController extends Controller
{
    public function context(PrivateInvitationTokenRequest $request, ResolveInvitationTrustContext $resolve): JsonResponse
    {
        return response()->json(['data' => $resolve->handle($request->validated('token'), $request)->toArray()]);
    }

    public function site(PrivateInvitationTokenRequest $request, ReadPrivateInvitationSite $read): JsonResponse
    {
        return response()->json(['data' => $read->handle($request->validated('token'), $request)]);
    }

    public function open(PrivateInvitationTokenRequest $request, OpenPrivateInvitation $open, InvitationTrustCookie $cookies): JsonResponse
    {
        $result = $open->handle($request->validated('token'), $request);
        $response = response()->json(['data' => $result->toArray()], $result->canOpen ? 200 : 409);

        if ($result->claimedNow) {
            $response->withCookie($cookies->make($result->credential, $result->secret));
        }

        return $response;
    }

    public function rsvp(SubmitPrivateInvitationRsvpRequest $request, SubmitPrivateInvitationRsvp $submit): JsonResponse
    {
        return response()->json(['data' => $submit->handle(
            $request->validated('token'),
            $request->validated('responses'),
            $request,
        )]);
    }

    public function requestAccess(PrivateInvitationTokenRequest $request, RequestInvitationAccessTransfer $create, InvitationTrustCookie $cookies): JsonResponse
    {
        $result = $create->handle($request->validated('token'), $request);
        $response = response()->json(['data' => $result->toArray()]);
        if ($result->credential !== null && $result->secret !== null) {
            $response->withCookie($cookies->make($result->credential, $result->secret));
        }

        return $response;
    }

    public function approveAccess(PrivateInvitationTokenRequest $request, ResolveInvitationAccessTransfer $resolve): JsonResponse
    {
        return response()->json(['data' => $resolve->handle($request->validated('token'), $request, true)]);
    }

    public function rejectAccess(PrivateInvitationTokenRequest $request, ResolveInvitationAccessTransfer $resolve): JsonResponse
    {
        return response()->json(['data' => $resolve->handle($request->validated('token'), $request, false)]);
    }
}
