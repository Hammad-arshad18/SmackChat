<?php

return [
    'standard_day_minutes' => 8 * 60,
    'retention_days' => (int) env('SMACKCHAT_RETENTION_DAYS', 90),
    'archive_price_cents' => (int) env('SMACKCHAT_ARCHIVE_PRICE_CENTS', 700),
    'currency' => 'usd',
];
