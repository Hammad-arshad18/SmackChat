<?php

return [
    'standard_day_minutes' => 8 * 60,
    'retention_days' => (int) env('SMACKCHAT_RETENTION_DAYS', 90),
    'archive_price_cents' => (int) env('SMACKCHAT_ARCHIVE_PRICE_CENTS', 700),
    'currency' => 'usd',

    // How long audit events are kept. HR/IT usually want at least a year.
    'audit_retention_days' => (int) env('SMACKCHAT_AUDIT_RETENTION_DAYS', 365),

    // 0 keeps attendance forever. Set a number when a local rule requires the
    // records to be destroyed after a fixed number of days.
    'attendance_retention_days' => (int) env('SMACKCHAT_ATTENDANCE_RETENTION_DAYS', 0),

    // Huddles. The free (standard) plan is one-to-one; the archive plan adds
    // group huddles. Media is peer to peer, so this is a seat cap for the mesh.
    'call_group_limit' => (int) env('SMACKCHAT_CALL_GROUP_LIMIT', 8),
    'call_stale_seconds' => (int) env('SMACKCHAT_CALL_STALE_SECONDS', 120),

    // Comma-separated STUN/TURN URLs. Add a TURN server when people are not on
    // the same office network; STUN alone cannot cross every firewall.
    'call_ice_servers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMACKCHAT_CALL_ICE_SERVERS', 'stun:stun.l.google.com:19302')),
    ))),

    // Optional TURN relay for people behind strict NAT/firewalls. Leave blank
    // to use STUN only. A TURN server needs long-term credentials. The URL list
    // is comma-separated so one credential can cover UDP, TCP, and TLS.
    'call_turn_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMACKCHAT_CALL_TURN_URL', '')),
    ))),
    'call_turn_username' => env('SMACKCHAT_CALL_TURN_USERNAME'),
    'call_turn_credential' => env('SMACKCHAT_CALL_TURN_CREDENTIAL'),
];
