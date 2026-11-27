<?php

namespace App\Http\Controllers\Api;

use App\Models\Call;
use App\Models\Channel;
use App\Services\CallIceServers;
use App\Services\CallService;
use App\Services\ChannelAccess;
use Illuminate\Http\Request;

class CallController extends ApiController
{
    public function active(Request $request, CallService $calls)
    {
        return response()->json([
            'calls' => $calls->active($this->workspace($request), $request->user()),
        ]);
    }

    public function store(Request $request, CallService $calls, ChannelAccess $access)
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:120'],
            'kind' => ['nullable', 'in:audio,video'],
        ]);

        $workspace = $this->workspace($request);
        $channel = $access->find($workspace, $data['channel']);
        $access->assertCanView($channel, $request->user());

        abort_if($channel->type === Channel::ATTENDANCE, 422, 'Huddles are not available in Timesheets.');

        $before = $calls->liveCall($channel)?->id;
        $call = $calls->start($channel, $request->user(), $data['kind'] ?? Call::AUDIO);
        $created = $call->id !== $before;
        $call->load('workspace');

        return response()->json([
            'call' => $calls->present($call, $request->user()),
            'created' => $created,
            'ice_servers' => app(CallIceServers::class)->all(),
        ], 201);
    }

    public function show(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);
        $calls->touch($call, $request->user());

        return response()->json([
            'call' => $calls->present($call, $request->user()),
        ]);
    }

    public function join(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);

        $call = $calls->join($call, $request->user());
        $call->load('workspace');

        return response()->json([
            'call' => $calls->present($call, $request->user()),
            'ice_servers' => app(CallIceServers::class)->all(),
        ]);
    }

    public function leave(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);
        $calls->leave($call, $request->user());

        return response()->json(['left' => true]);
    }

    public function decline(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);
        $calls->decline($call, $request->user());

        return response()->json(['declined' => true]);
    }

    public function signal(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);

        $data = $request->validate([
            'to' => ['required', 'integer'],
            'type' => ['required', 'in:offer,answer,candidate,refresh,status'],
            'payload' => ['required', 'array'],
        ]);

        abort_if(
            strlen((string) json_encode($data['payload'])) > 24000,
            422,
            'That signal is too large.',
        );

        $calls->signal($call, $request->user(), (int) $data['to'], $data['type'], $data['payload']);

        return response()->json(['sent' => true]);
    }

    public function signals(Request $request, string $workspace, Call $call, CallService $calls, ChannelAccess $access)
    {
        $this->ensureCall($request, $call, $access);
        $after = max(0, (int) $request->query('after', 0));

        return response()->json([
            'signals' => $calls->signals($call, $request->user(), $after),
        ]);
    }

    private function ensureCall(Request $request, Call $call, ChannelAccess $access): void
    {
        abort_unless((int) $call->workspace_id === (int) $this->workspace($request)->id, 404);
        $access->assertCanView($call->channel, $request->user());
    }
}
