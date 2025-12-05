<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | License Validation Settings
    |--------------------------------------------------------------------------
    |
    | Cấu hình cho việc validation license của ứng dụng
    |
    */

    // Tên key lưu trong settings table
    'setting_key' => env('LICENSE_SETTING_KEY', 'theme_validate'),

    // Bật/tắt license validation
    'validation_enabled' => env('LICENSE_VALIDATION_ENABLED', true),

    // Tự động clear cache khi update license
    'auto_clear_cache' => env('LICENSE_AUTO_CLEAR_CACHE', true),

    // Timeout cho việc validation (seconds)
    'validation_timeout' => env('LICENSE_VALIDATION_TIMEOUT', 30),

    // Log level cho license operations
    'log_level' => env('LICENSE_LOG_LEVEL', 'info'),

    // Marketplace settings
    'marketplace' => [
        'url' => env('MARKETPLACE_URL', 'https://sudo.vn'),
        'token' => env('MARKETPLACE_TOKEN', 'uDLg2Ktg3PgI5fuDuLvzkPOXABOK9WVjYC3xv7d9lEd4O7Pe1pwfIevMGPHI'),
    ],

    // Các API endpoints được cho phép update license
    'allowed_update_ips' => [
        // sudo.vn IPs
        '127.0.0.1',
        '::1',
    ],

    // API rate limiting
    'rate_limit' => [
        'enabled' => env('LICENSE_RATE_LIMIT_ENABLED', true),
        'max_attempts' => env('LICENSE_RATE_LIMIT_MAX', 10),
        'decay_minutes' => env('LICENSE_RATE_LIMIT_DECAY', 60),
    ],

    // License validation rules
    'validation_rules' => [
        'domain_check' => env('LICENSE_DOMAIN_CHECK', true),
        'expiry_check' => env('LICENSE_EXPIRY_CHECK', true),
    ],

    // Middleware settings
    'middleware' => [
        'enabled' => env('LICENSE_MIDDLEWARE_ENABLED', true), // Bật mặc định
        'exclude_routes' => [
            'api/license/*',
            'admin/license/*',
        ],
    ],
];
