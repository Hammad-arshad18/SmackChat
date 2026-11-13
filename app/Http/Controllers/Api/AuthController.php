<?php

namespace App\Http\Controllers\Api;

use App\Mail\RegistrationCodeMail;
use App\Models\RegistrationChallenge;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends ApiController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'workspace_name' => ['required', 'string', 'max:80'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $email = strtolower($data['email']);
        $code = $this->makeCode();
        $challenge = RegistrationChallenge::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $data['name'],
                'password' => $data['password'],
                'workspace_name' => $data['workspace_name'],
                'timezone' => $data['timezone'] ?? 'UTC',
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
                'attempts' => 0,
                'verified_at' => null,
            ]
        );

        Mail::to($email)->send(new RegistrationCodeMail($code));

        return response()->json([
            'pending' => true,
            'email' => $email,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'message' => 'A six-digit verification code is on its way to '.$email.'.',
        ], 202);
    }

    public function verifyRegistration(Request $request, WorkspaceProvisioner $provisioner)
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
        $this->establishSession($request, $user);

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
            'workspace_slug' => $workspace->slug,
        ], 201);
    }

    public function resendRegistrationCode(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);
        $challenge = RegistrationChallenge::query()->where('email', $email)->latest('id')->first();

        if (! $challenge) {
            return response()->json(['message' => 'Start a new workspace registration first.'], 422);
        }

        $code = $this->issueCode($challenge);
        Mail::to($email)->send(new RegistrationCodeMail($code));

        return response()->json([
            'pending' => true,
            'email' => $email,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'message' => 'A fresh code is on its way.',
        ]);
    }

    private function issueCode(RegistrationChallenge $challenge): string
    {
        $code = $this->makeCode();
        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ])->save();

        return $code;
    }

    private function makeCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Those credentials do not match an account.'], 422);
        }

        $this->establishSession($request, $user);

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:80'],
            'timezone' => ['sometimes', 'timezone:all'],
            'status_text' => ['nullable', 'string', 'max:60'],
            'status_emoji' => ['nullable', 'string', 'max:12'],
        ]);

        $request->user()->update($data);

        return response()->json([
            'user' => $this->userPayload($request->user()->fresh()),
        ]);
    }

    public function updateAvatar(Request $request)
    {
        $data = $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $user = $request->user();
        $this->deleteAvatar($user);
        $file = $data['avatar'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $path = $file->storeAs('avatars/'.$user->id, Str::uuid()->toString().'.'.$extension, 'local');
        $user->forceFill(['avatar_path' => $path])->save();

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function destroyAvatar(Request $request)
    {
        $user = $request->user();
        $this->deleteAvatar($user);
        $user->forceFill(['avatar_path' => null])->save();

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function avatar(Request $request, User $user)
    {
        abort_unless($this->sharesWorkspace($request->user(), $user), 403);
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        $etag = '"'.sha1($user->id.'|'.$user->avatar_path.'|'.$user->updated_at).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=604800',
            ]);
        }

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=604800',
            'ETag' => $etag,
        ]);
    }

    public function heartbeat(Request $request)
    {
        $request->user()->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    private function deleteAvatar(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }
    }

    private function sharesWorkspace(User $viewer, User $subject): bool
    {
        if ($viewer->id === $subject->id) {
            return true;
        }

        $workspaceIds = $viewer->workspaces()->pluck('workspaces.id');

        return $subject->workspaces()->whereIn('workspaces.id', $workspaceIds)->exists();
    }

    private function establishSession(Request $request, User $user): void
    {
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        if (! $request->hasSession()) {
            return;
        }

        Auth::login($user, true);
        $request->session()->regenerate();
    }
}
