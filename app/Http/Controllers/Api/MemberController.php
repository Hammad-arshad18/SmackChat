<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberController extends ApiController
{
    public function index(Request $request)
    {
        $workspace = $this->workspace($request);
        $members = $workspace->members()->orderBy('name')->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'title' => $user->title,
            'role' => $user->pivot->role,
            'avatar_color' => $user->avatar_color,
            'avatar_url' => $user->avatarUrl(),
            'status_text' => $user->status_text,
            'status_emoji' => $user->status_emoji,
            'last_seen_at' => optional($user->last_seen_at)?->toIso8601String(),
            'joined_at' => optional($user->pivot->created_at)?->toIso8601String(),
            'online' => $user->id === $request->user()?->id ? true : $user->isOnline(),
        ]);

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'url' => url('/invite/'.$invitation->token),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]);

        return response()->json([
            'members' => $members,
            'invitations' => $this->membership($request)->managesOffice() ? $invitations : [],
        ]);
    }

    public function invite(Request $request)
    {
        $this->requireManager($request);
        $workspace = $this->workspace($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in([WorkspaceMember::ADMIN, WorkspaceMember::MEMBER])],
        ]);

        $email = strtolower($data['email']);
        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing && $workspace->members()->where('user_id', $existing->id)->exists()) {
            return response()->json(['message' => 'That person is already in this workspace.'], 422);
        }

        $pending = $workspace->invitations()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'An invite is already waiting for that email.'], 422);
        }

        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $data['role'],
            'token' => Str::random(48),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(14),
        ]);

        $url = url('/invite/'.$invitation->token);

        try {
            Mail::raw(
                $request->user()->name.' invited you to '.$workspace->name.' on SmackChat. Join here: '.$url,
                function ($mail) use ($invitation, $workspace) {
                    $mail->to($invitation->email)->subject('Join '.$workspace->name.' on SmackChat');
                }
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'url' => $url,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    public function updateRole(Request $request, string $workspace, User $user)
    {
        $this->requireOwner($request);
        $workspaceModel = $this->workspace($request);
        $data = $request->validate([
            'role' => ['required', Rule::in([WorkspaceMember::ADMIN, WorkspaceMember::MEMBER])],
        ]);

        $target = WorkspaceMember::query()
            ->where('workspace_id', $workspaceModel->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        abort_if($target->role === WorkspaceMember::OWNER, 422, 'The owner role cannot be changed.');
        $target->update(['role' => $data['role']]);

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $target->role,
            ],
        ]);
    }

    public function revokeInvitation(Request $request, string $workspace, Invitation $invitation)
    {
        $this->requireManager($request);
        abort_unless($invitation->workspace_id === $this->workspace($request)->id, 404);
        $invitation->delete();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $workspace, User $user)
    {
        $workspaceModel = $this->workspace($request);
        $actor = $this->membership($request);
        $target = WorkspaceMember::query()
            ->where('workspace_id', $workspaceModel->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        abort_if($target->role === WorkspaceMember::OWNER, 422, 'The owner stays with the workspace.');

        $self = $actor->user_id === $user->id;

        if (! $self) {
            if ($actor->role === WorkspaceMember::ADMIN && $target->role !== WorkspaceMember::MEMBER) {
                abort(403, 'Admins can remove members.');
            }
            abort_unless($actor->managesOffice(), 403, 'You cannot remove that person.');
        }

        DB::transaction(function () use ($workspaceModel, $user, $target) {
            $channelIds = $workspaceModel->channels()->pluck('id');
            DB::table('channel_user')->where('user_id', $user->id)->whereIn('channel_id', $channelIds)->delete();
            $target->delete();

            if ($user->current_workspace_id === $workspaceModel->id) {
                $user->forceFill(['current_workspace_id' => null])->save();
            }
        });

        return response()->json(['ok' => true]);
    }
}
