<?php

namespace App\Http\Controllers\Api;

use App\Models\Channel;
use App\Models\ChannelKeyWrap;
use App\Models\E2eDevice;
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

            if ($existing->isEmpty()) {
                $includesCaller = collect($data['wraps'])->contains(
                    fn (array $wrap) => in_array((int) $wrap['device_id'], $callerDeviceIds, true)
                );
                if (! $includesCaller) {
                    throw ValidationException::withMessages([
                        'wraps' => 'Register this device before starting the conversation key.',
                    ]);
                }
            } elseif (! $callerHasKey) {
                abort(409, 'This conversation key is already held by another device.');
            }

            foreach ($data['wraps'] as $wrap) {
                $deviceId = (int) $wrap['device_id'];
                if (! $devices->has($deviceId)) {
                    throw ValidationException::withMessages([
                        'wraps' => 'One of those devices cannot join this conversation.',
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
