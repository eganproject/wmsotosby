<?php

return [
    'enabled' => (bool) env('STOCK_API_ENABLED', true),
    'warehouse_code' => env('STOCK_API_WAREHOUSE_CODE', 'WMSOTOSBY'),
    'token' => env('STOCK_API_TOKEN'),
    'rate_limit_per_minute' => (int) env('STOCK_API_RATE_LIMIT_PER_MINUTE', 60),
];
