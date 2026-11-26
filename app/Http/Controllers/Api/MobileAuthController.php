<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SignsPeopleIn;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-in for the native app. A phone has no cookie jar, so instead of a
 * session it gets a Sanctum bearer token named after the device. The checks
 * are the browser's own (`SignsPeopleIn`), including the login lockout.
 */
class MobileAuthController extends ApiController
{
    use SignsPeopleIn;

    public function login(Request $request)
    {
        $deviceName = $this->deviceName($request);
        $user = $this->checkCredentials($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json($this->issue($user, $deviceName));
    }

    public function verifyRegistration(Request $request, WorkspaceProvisioner $provisioner)
    {
        $deviceName = $this->deviceName($request);
        $result = $this->completeRegistration($request, $provisioner);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$user, $workspace] = $result;

        return response()->json($this->issue($user, $deviceName, $workspace->slug), 201);
    }

    /**
     * A new person sends name + password and gets a token. Someone already
     * signed in on the phone sends their bearer token instead; they keep it
     * and `token` comes back null.
     */
    public function acceptInvitation(Request $request, string $token, WorkspaceProvisioner $provisioner)
    {
        $viewer = Auth::guard('sanctum')->user();
        $deviceName = $viewer ? null : $this->deviceName($request);
        $result = $this->admitInvitee($request, $token, $viewer, $provisioner);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $user = $result['user'];
        $slug = $result['invitation']->workspace->slug;

        if ($deviceName === null) {
            return response()->json([
                'user' => $this->userPayload($user->fresh()),
                'token' => null,
                'workspace_slug' => $slug,
            ]);
        }

        return response()->json($this->issue($user, $deviceName, $slug));
    }

    public function logout(Request $request)
    {
        $this->revokeBearerToken($request);

        return response()->json(['ok' => true]);
    }

    private function deviceName(Request $request): string
    {
        $data = $request->validate([
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        return trim($data['device_name']);
    }

    /**
     * @return array{user: array<string, mixed>, token: string, workspace_slug?: string}
     */
    private function issue(User $user, string $deviceName, ?string $workspaceSlug = null): array
    {
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        $token = $user->createToken('mobile:'.$deviceName)->plainTextToken;

        $payload = [
            'user' => $this->userPayload($user->fresh()),
            'token' => $token,
        ];

        if ($workspaceSlug !== null) {
            $payload['workspace_slug'] = $workspaceSlug;
        }

        return $payload;
    }
}
