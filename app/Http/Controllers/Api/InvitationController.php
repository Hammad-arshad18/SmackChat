<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class InvitationController extends ApiController
{
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
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();
        abort_unless($invitation->isOpen(), 410, 'This invitation is no longer active.');

        $user = $request->user();

        if ($user && strcasecmp($user->email, $invitation->email) !== 0) {
            return response()->json([
                'message' => 'Log in as '.$invitation->email.' to accept this invite.',
            ], 403);
        }

        if (! $user) {
            if (User::query()->whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists()) {
                return response()->json([
                    'message' => 'An account already uses this email. Log in, then open the invite again.',
                ], 422);
            }

            $data = $request->validate([
                'name' => ['required', 'string', 'max:80'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($invitation->email),
                'password' => $data['password'],
                'timezone' => $invitation->workspace->timezone,
                'avatar_color' => User::colorFor($invitation->email),
            ]);

            if ($request->hasSession()) {
                Auth::login($user);
                $request->session()->regenerate();
            }
        }

        $provisioner->admit($invitation->workspace, $user, $invitation->role);
        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
            'workspace_slug' => $invitation->workspace->slug,
        ]);
    }
}
