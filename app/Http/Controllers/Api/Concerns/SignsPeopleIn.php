<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\DevicePushToken;
use App\Models\Invitation;
use App\Models\RegistrationChallenge;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The checks behind signing in, finishing a registration and accepting an
 * invite. The browser (cookie session) and the mobile app (bearer token) both
 * run through here so the two paths cannot drift; each controller only
 * decides how the signed-in person is remembered.
 */
trait SignsPeopleIn
{
    /**
     * Email + password with the lockout: five misses per email and IP, then a
     * 300-second wait.
     */
    protected function checkCredentials(Request $request): User|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower($data['email']);
        $throttleKey = 'login|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ], 429);
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Count failures per email + IP so one noisy client cannot lock an
            // entire office out, and a distributed attacker still hits a wall.
            RateLimiter::hit($throttleKey, 300);

            return response()->json(['message' => 'Those credentials do not match an account.'], 422);
        }

        RateLimiter::clear($throttleKey);

        return $user;
    }

    /**
     * Check the emailed code and create the account and its workspace.
     *
     * @return array{0: User, 1: Workspace}|JsonResponse
     */
    protected function completeRegistration(Request $request, WorkspaceProvisioner $provisioner): array|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $email = strtolower($data['email']);
        $challenge = RegistrationChallenge::query()->where('email', $email)->latest('id')->first();

        if (! $challenge) {
            return response()->json(['message' => 'Start a new workspace registration first.'], 422);
        }

        if ($challenge->isExpired()) {
            return response()->json(['message' => 'That code expired. Request a fresh one.'], 410);
        }

        if ($challenge->attempts >= 5) {
            return response()->json(['message' => 'Too many attempts. Request a fresh code.'], 429);
        }

        if (! Hash::check($data['code'], $challenge->code_hash)) {
            $challenge->increment('attempts');
            $remaining = max(0, 5 - $challenge->attempts);

            return response()->json([
                'message' => 'That code does not match.'.($remaining ? ' You have '.$remaining.' more attempts.' : ' Request a fresh code.'),
            ], 422);
        }

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $challenge->delete();

            return response()->json(['message' => 'That email is already registered. Log in instead.'], 422);
        }

        $user = User::query()->create([
            'name' => $challenge->name,
            'email' => $email,
            'password' => $challenge->getRawOriginal('password'),
            'timezone' => $challenge->timezone,
            'avatar_color' => User::colorFor($email),
        ]);

        $workspace = $provisioner->create($user, $challenge->workspace_name, $challenge->timezone);
        $challenge->delete();

        return [$user, $workspace];
    }

    /**
     * Accept an invite as the signed-in person, or create the invited account.
     * `created` is true when a new account was made here.
     *
     * @return array{user: User, invitation: Invitation, created: bool}|JsonResponse
     */
    protected function admitInvitee(
        Request $request,
        string $token,
        ?User $viewer,
        WorkspaceProvisioner $provisioner,
    ): array|JsonResponse {
        $invitation = Invitation::query()->where('token', $token)->firstOrFail();
        abort_unless($invitation->isOpen(), 410, 'This invitation is no longer active.');

        $user = $viewer;
        $created = false;

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
            $created = true;
        }

        $provisioner->admit($invitation->workspace, $user, $invitation->role);
        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->forceFill([
            'current_workspace_id' => $invitation->workspace_id,
            'last_seen_at' => now(),
        ])->saveQuietly();

        return ['user' => $user, 'invitation' => $invitation, 'created' => $created];
    }

    /**
     * Revoke the bearer token this request came in with, and the native push
     * tokens that phone registered under it. A cookie session has no such
     * token, so this does nothing for the browser.
     */
    protected function revokeBearerToken(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        DevicePushToken::query()->where('personal_access_token_id', $token->getKey())->delete();
        $token->delete();
    }
}
