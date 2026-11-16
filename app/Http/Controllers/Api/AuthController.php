<?php

namespace App\Http\Controllers\Api;

use App\Mail\RegistrationCodeMail;
use App\Models\AttendanceDay;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\RegistrationChallenge;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\WorkspaceProvisioner;
use App\Support\OpaqueId;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
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

        defer(fn () => Mail::to($email)->send(new RegistrationCodeMail($code)));

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
        defer(fn () => Mail::to($email)->send(new RegistrationCodeMail($code)));

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
        $this->establishSession($request, $user);

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        // Never reveal whether the address exists, and do not fail the
        // request when mail is briefly down. Deferred so a slow SMTP server
        // cannot stall the response.
        defer(function () use ($email): void {
            try {
                PasswordBroker::sendResetLink(['email' => $email]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        return response()->json([
            'message' => 'If that email belongs to an account, a reset link is on its way.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordBroker::reset(
            [
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'] ?? $data['password'],
                'token' => $data['token'],
            ],
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset signs every other device out.
                $user->tokens()->delete();

                event(new PasswordReset($user));
                $this->establishSession($request, $user);
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json([
            'ok' => true,
            'user' => $request->user() ? $this->userPayload($request->user()->fresh()) : null,
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

    /**
     * Personal data export. Message bodies that were sealed end-to-end are
     * included as ciphertext; the browser is the only place they can be read.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $limit = 5000;

        $messages = Message::query()
            ->with('channel:id,workspace_id,slug,name')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $truncated = $messages->count() > $limit;
        $messages = $messages->take($limit);

        $days = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->orderBy('work_date')
            ->get();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $this->userPayload($user->fresh()),
            'workspaces' => $user->workspaces()->orderBy('name')->get()->map(fn (Workspace $workspace) => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $workspace->pivot->role,
                'joined_at' => optional($workspace->pivot->created_at)?->toIso8601String(),
            ])->values(),
            'messages' => $messages->map(fn (Message $message) => [
                'ref' => OpaqueId::encode($message->id),
                'channel' => $message->channel?->slug,
                'cipher' => $message->cipher,
                'ciphertext' => $message->ciphertext,
                'created_at' => optional($message->created_at)?->toIso8601String(),
                'edited_at' => optional($message->edited_at)?->toIso8601String(),
                'deleted' => $message->deleted_at !== null,
            ])->values(),
            'messages_truncated' => $truncated,
            'attendance' => $days->map(fn (AttendanceDay $day) => [
                'date' => $day->work_date?->toDateString(),
                'status' => $day->status,
                'check_in_at' => optional($day->check_in_at)?->toIso8601String(),
                'check_out_at' => optional($day->check_out_at)?->toIso8601String(),
                'work_minutes' => (int) $day->work_minutes,
                'break_minutes' => (int) $day->break_minutes,
            ])->values(),
            'push_subscriptions' => PushSubscription::query()->where('user_id', $user->id)->count(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="smackchat-export-'.$user->id.'.json"',
        ]);
    }

    /**
     * Delete the account. Workspaces where this person is the only member go
     * with it; workspaces they share must be handed over first.
     */
    public function destroy(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'That password is not correct.'], 422);
        }

        $ownedAlone = Workspace::query()
            ->where('owner_id', $user->id)
            ->whereDoesntHave('members', fn ($query) => $query->where('users.id', '!=', $user->id))
            ->get();

        $ownedWithOthers = Workspace::query()
            ->where('owner_id', $user->id)
            ->whereHas('members', fn ($query) => $query->where('users.id', '!=', $user->id))
            ->exists();

        if ($ownedWithOthers) {
            return response()->json([
                'message' => 'Hand the workspace to another owner before deleting your account.',
            ], 422);
        }

        DB::transaction(function () use ($user, $ownedAlone): void {
            foreach ($ownedAlone as $workspace) {
                $workspace->delete();
            }

            $user->tokens()->delete();
            $user->forceFill(['current_workspace_id' => null])->saveQuietly();
            $user->delete();
        });

        $audit->record('account.deleted', null, null, [
            'email' => $user->email,
            'user_id' => $user->id,
            'owned_workspaces_removed' => $ownedAlone->pluck('slug')->all(),
        ], null, $request);

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
