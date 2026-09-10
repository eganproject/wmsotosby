<?php

return [
    /*
     * Klasifikasi dinormalisasi ke laju 30 hari supaya hasilnya tetap sama
     * ketika pengguna melihat rentang 7, 30, atau 90 hari.
     *
     * Default:
     * - Fast Moving   : >= 30 unit / 30 hari
     * - Medium Moving : >= 10 dan < 30 unit / 30 hari
     * - Slow Moving   : > 0 dan < 10 unit / 30 hari
     * - Non-Moving    : 0 unit / 30 hari
     */
    'fast_moving_min_30_days' => (int) env('STOCK_FAST_MOVING_MIN_30_DAYS', 30),
    'medium_moving_min_30_days' => (int) env('STOCK_MEDIUM_MOVING_MIN_30_DAYS', 10),
];
