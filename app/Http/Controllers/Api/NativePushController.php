<?php

namespace App\Http\Controllers\Api;

use App\Models\DevicePushToken;
use App\Services\NativePushNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push addresses for the native app. The phone registers one row per kind:
 * an Expo token for messages, plus a PushKit VoIP token (iOS) or an FCM
 * token (Android) so calls ring while the app is closed.
 */
class NativePushController extends ApiController
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in([DevicePushToken::IOS, DevicePushToken::ANDROID])],
            'kind' => ['required', 'string', Rule::in([DevicePushToken::EXPO, DevicePushToken::VOIP, DevicePushToken::FCM])],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $token = trim($data['token']);

        if ($data['kind'] === DevicePushToken::VOIP && $data['platform'] !== DevicePushToken::IOS) {
            return response()->json(['message' => 'A VoIP token comes from an iPhone.'], 422);
        }

        if ($data['kind'] === DevicePushToken::FCM && $data['platform'] !== DevicePushToken::ANDROID) {
            return response()->json(['message' => 'An FCM token comes from an Android phone.'], 422);
        }

        if ($data['kind'] === DevicePushToken::EXPO && ! preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $token)) {
            return response()->json(['message' => 'That is not an Expo push token.'], 422);
        }

        if ($data['kind'] === DevicePushToken::VOIP && ! preg_match('/^[0-9a-fA-F]{64,200}$/', $token)) {
            return response()->json(['message' => 'That is not a VoIP device token.'], 422);
        }

        $accessToken = $request->user()->currentAccessToken();

        // A token moves with the phone: if someone else signed in on this
        // device before, the row is handed to the person signed in now.
        DevicePushToken::query()->updateOrCreate(
            ['token_hash' => DevicePushToken::hashFor($token)],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'kind' => $data['kind'],
                'token' => $token,
                'personal_access_token_id' => $accessToken instanceof PersonalAccessToken ? $accessToken->getKey() : null,
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Send a test notice to this person's phones and report what happened. */
    public function test(Request $request, NativePushNotifier $push)
    {
        return response()->json($push->test($request->user()));
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        DevicePushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token_hash', DevicePushToken::hashFor(trim($data['token'])))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
