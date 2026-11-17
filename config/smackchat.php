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
];
