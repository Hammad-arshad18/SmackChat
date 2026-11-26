<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SignsPeopleIn;
use App\Models\Invitation;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvitationController extends ApiController
{
    use SignsPeopleIn;

    public function show(string $token)
    {
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();
        $invitation->load('workspace');

        return response()->json([
            'invitation' => [
                'workspace' => $invitation->workspace->name,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'open' => $invitation->isOpen(),
            ],
        ]);
    }

    public function accept(Request $request, string $token, WorkspaceProvisioner $provisioner)
    {
        $result = $this->admitInvitee($request, $token, $request->user(), $provisioner);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        if ($result['created'] && $request->hasSession()) {
            Auth::login($result['user']);
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => $this->userPayload($result['user']->fresh()),
            'workspace_slug' => $result['invitation']->workspace->slug,
        ]);
    }
}
