<?php

namespace App\Http\Controllers\Api;

use App\Models\PushSubscription;
use App\Services\WebPushNotifier;
use Illuminate\Http\Request;

class PushController extends ApiController
{
    public function key(WebPushNotifier $push)
    {
        $publicKey = $push->publicKey();

        return response()->json([
            'enabled' => $publicKey !== null,
            'publicKey' => $publicKey,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'public_key' => ['required', 'string', 'max:255'],
            'auth_token' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['public_key'],
                'auth_token' => $data['auth_token'],
                'content_encoding' => $data['content_encoding'] ?: 'aes128gcm',
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, WebPushNotifier $push)
    {
        return response()->json($push->ping($request->user()));
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
