<?php

namespace App\Http\Controllers\Api;

use App\Models\Channel;
use App\Models\ChannelKeyWrap;
use App\Models\E2eChannelBackup;
use App\Models\E2eDevice;
use App\Models\E2eVault;
use App\Models\WorkspaceMember;
use App\Services\ChannelAccess;
use App\Support\E2eSealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class E2eController extends ApiController
{
    public function device(Request $request)
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:200'],
        ]);

        $raw = base64_decode($data['public_key'], true);
        if (! is_string($raw) || strlen($raw) !== 65 || $raw[0] !== "\x04") {
            throw ValidationException::withMessages([
                'public_key' => 'The encryption key is not valid.',
            ]);
        }

        $publicKey = base64_encode($raw);
        $existing = E2eDevice::query()->where('public_key', $publicKey)->first();

        if ($existing && $existing->user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'public_key' => 'That encryption key is already registered.',
            ]);
        }

        $device = $existing ?: new E2eDevice([
            'user_id' => $request->user()->id,
            'public_key' => $publicKey,
        ]);
        $device->last_seen_at = now();
        $device->save();

        return response()->json([
            'device' => [
                'id' => $device->id,
                'public_key' => $device->public_key,
            ],
        ]);
    }

    public function showVault(Request $request)
    {
        $vault = E2eVault::query()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'vault' => $vault ? [
                'salt' => $vault->salt,
                'wrapped_master' => $vault->wrapped_master,
            ] : null,
        ]);
    }

    public function updateVault(Request $request)
    {
        $data = $request->validate([
            'salt' => ['required', 'string', 'max:64'],
            'wrapped_master' => ['required', 'string', 'max:400'],
        ]);

        $salt = base64_decode($data['salt'], true);
        if (! is_string($salt) || strlen($salt) !== 16) {
            throw ValidationException::withMessages([
                'salt' => 'The encryption vault salt is not valid.',
            ]);
        }

        E2eSealer::assertVaultBlob($data['wrapped_master']);

        // Never replace an existing vault — a second browser must unlock the same master.
        $vault = E2eVault::query()->firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'salt' => base64_encode($salt),
                'wrapped_master' => $data['wrapped_master'],
            ]
        );

        return response()->json([
            'vault' => [
                'salt' => $vault->salt,
                'wrapped_master' => $vault->wrapped_master,
            ],
        ]);
    }

    public function showBackup(Request $request, int $channel, ChannelAccess $access)
    {
        $model = Channel::query()->findOrFail($channel);
        $this->assertChannelMember($request, $model, $access);

        $backup = E2eChannelBackup::query()
            ->where('user_id', $request->user()->id)
            ->where('channel_id', $model->id)
            ->first();

        return response()->json([
            'backup' => $backup ? [
                'channel_id' => $backup->channel_id,
                'wrapped' => $backup->wrapped,
            ] : null,
        ]);
    }

    public function updateBackups(Request $request, ChannelAccess $access)
    {
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1', 'max:200'],
            'channels.*.channel_id' => ['required', 'integer'],
            'channels.*.wrapped' => ['required', 'string', 'max:400'],
        ]);

        foreach ($data['channels'] as $row) {
            E2eSealer::assertBackupBlob($row['wrapped']);
        }

        $saved = [];

        foreach ($data['channels'] as $row) {
            $model = Channel::query()->findOrFail((int) $row['channel_id']);
            $this->assertChannelMember($request, $model, $access);

            $backup = E2eChannelBackup::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'channel_id' => $model->id,
                ],
                ['wrapped' => $row['wrapped']]
            );

            $saved[] = [
                'channel_id' => $backup->channel_id,
                'wrapped' => $backup->wrapped,
            ];
        }

        return response()->json(['channels' => $saved]);
    }

    public function show(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $model = $access->find($this->workspace($request), $channel);
        $access->assertCanView($model, $request->user());

        return response()->json($this->bundle($model));
    }

    public function update(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $model = $access->find($this->workspace($request), $channel);
        $access->assertCanView($model, $request->user());
        $data = $request->validate([
            'wraps' => ['required', 'array', 'min:1', 'max:200'],
            'wraps.*.device_id' => ['required', 'integer'],
            'wraps.*.wrapped' => ['required', 'string', 'max:400'],
        ]);

        foreach ($data['wraps'] as $wrap) {
            E2eSealer::assertWrapBlob($wrap['wrapped']);
        }

        $bundle = DB::transaction(function () use ($request, $model, $data) {
            Channel::query()->whereKey($model->id)->lockForUpdate()->first();
            $devices = $this->devices($model)->keyBy('id');
            $callerDeviceIds = $devices
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->all();
            $existing = ChannelKeyWrap::query()
                ->where('channel_id', $model->id)
                ->lockForUpdate()
                ->get();
            $callerHasKey = $existing->contains(fn (ChannelKeyWrap $wrap) => in_array($wrap->device_id, $callerDeviceIds, true));
            $callerHasBackup = E2eChannelBackup::query()
                ->where('channel_id', $model->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->exists();

            if ($existing->isEmpty()) {
                $includesCaller = collect($data['wraps'])->contains(
                    fn (array $wrap) => in_array((int) $wrap['device_id'], $callerDeviceIds, true)
                );
                if (! $includesCaller) {
                    throw ValidationException::withMessages([
                        'wraps' => 'Register this device before starting the conversation key.',
                    ]);
                }
            } elseif (! $callerHasKey && ! $callerHasBackup) {
                abort(409, 'This conversation key is already held by another device.');
            }

            foreach ($data['wraps'] as $wrap) {
                $deviceId = (int) $wrap['device_id'];
                if (! $devices->has($deviceId)) {
                    throw ValidationException::withMessages([
                        'wraps' => 'One of those devices cannot join this conversation.',
                    ]);
                }

                if (! $callerHasKey && $callerHasBackup && (int) $devices->get($deviceId)->user_id !== $request->user()->id) {
                    throw ValidationException::withMessages([
                        'wraps' => 'Restore this device before sharing keys with teammates.',
                    ]);
                }

                ChannelKeyWrap::query()->firstOrCreate(
                    [
                        'channel_id' => $model->id,
                        'device_id' => $deviceId,
                    ],
                    ['wrapped' => $wrap['wrapped']]
                );
            }

            return $this->bundle($model);
        });

        return response()->json($bundle);
    }

    private function assertChannelMember(Request $request, Channel $channel, ChannelAccess $access): void
    {
        $member = WorkspaceMember::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('user_id', $request->user()->id)
            ->exists();
        abort_unless($member, 403, 'That conversation is private.');
        $access->assertCanView($channel, $request->user());
    }

    private function bundle(Channel $channel): array
    {
        $devices = $this->devices($channel);
        $wraps = ChannelKeyWrap::query()
            ->where('channel_id', $channel->id)
            ->whereIn('device_id', $devices->pluck('id'))
            ->get(['device_id', 'wrapped']);

        return [
            'devices' => $devices->map(fn (E2eDevice $device) => [
                'id' => $device->id,
                'user_id' => $device->user_id,
                'public_key' => $device->public_key,
            ])->values()->all(),
            'wraps' => $wraps->map(fn (ChannelKeyWrap $wrap) => [
                'device_id' => $wrap->device_id,
                'wrapped' => $wrap->wrapped,
            ])->values()->all(),
        ];
    }

    private function devices(Channel $channel)
    {
        $ids = $channel->isOpen()
            ? DB::table('workspace_members')->where('workspace_id', $channel->workspace_id)->pluck('user_id')
            : $channel->members()->pluck('users.id');

        return E2eDevice::query()
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->get(['id', 'user_id', 'public_key']);
    }
}
