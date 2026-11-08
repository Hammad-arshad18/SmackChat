<?php

return [
    'subject' => env('VAPID_SUBJECT', 'mailto:notifications@smack.chat'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
];
