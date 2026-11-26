<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push address on the native app. `expo` carries message alerts; `voip`
 * (iOS PushKit) and `fcm` (Android data message) ring the phone's own call
 * screen while the app is closed.
 */
class DevicePushToken extends Model
{
    public const EXPO = 'expo';

    public const VOIP = 'voip';

    public const FCM = 'fcm';

    public const IOS = 'ios';

    public const ANDROID = 'android';

    protected $fillable = [
        'user_id',
        'platform',
        'kind',
        'token_hash',
        'token',
        'personal_access_token_id',
    ];

    protected $hidden = ['token'];

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
