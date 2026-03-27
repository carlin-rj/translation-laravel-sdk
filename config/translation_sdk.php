<?php

declare(strict_types=1);

return [
    // 远程翻译系统连接配置。
    'gateway' => [
        'base_url' => env('TRANSLATION_GATEWAY_BASE_URL', 'http://127.0.0.1:8000/api'),
        'system_token' => env('TRANSLATION_SYSTEM_TOKEN', ''),
        'timeout' => (float) env('TRANSLATION_GATEWAY_TIMEOUT', 2.0),
    ],

    // 主动扫描与被动采集配置。
    'collect' => [
        // 主动扫描默认批量上报条数。
        'batch_size' => (int) env('TRANSLATION_COLLECT_BATCH_SIZE', 200),

        // `translation-sdk:collect-active` 默认扫描路径。
        'scan_paths' => [
            app_path(),
            resource_path('views'),
        ],

        // 主动扫描包含的文件后缀。
        'scan_extensions' => ['php', 'blade.php', 'vue', 'js', 'ts'],

        // 主动扫描时排除的路径片段。
        'exclude_paths' => [
            'vendor',
            'storage',
            'bootstrap/cache',
        ],

        'passive' => [
            // 是否开启运行时缺失翻译采集。
            'enabled' => (bool) env('TRANSLATION_PASSIVE_ENABLED', true),

            // 缓冲区达到阈值时触发自动 flush。
            'flush_threshold' => (int) env('TRANSLATION_PASSIVE_FLUSH_THRESHOLD', 100),

            // 自动 / 手动 flush 默认批次大小。
            'flush_batch_size' => (int) env('TRANSLATION_PASSIVE_FLUSH_BATCH_SIZE', 200),

            // 缺失项 buffer 使用的 Redis 连接名。
            'redis_connection' => env('TRANSLATION_PASSIVE_REDIS_CONNECTION', 'default'),

            // Redis 分桶数量，用于避免单个 key 过大。
            'redis_bucket_count' => (int) env('TRANSLATION_PASSIVE_REDIS_BUCKET_COUNT', 16),

            // 单个 Redis 分桶允许的最大条数。
            'redis_max_bucket_size' => (int) env('TRANSLATION_PASSIVE_REDIS_MAX_BUCKET_SIZE', 5000),

            // 相同 key 缺失项的冷却时间（秒）。
            'report_cooldown_seconds' => (int) env('TRANSLATION_PASSIVE_REPORT_COOLDOWN_SECONDS', 600),
        ],
    ],

    // 远程翻译包同步配置。
    'sync' => [
        'batch_size' => (int) env('TRANSLATION_SYNC_BATCH_SIZE', 200),
        'max_pages' => (int) env('TRANSLATION_SYNC_MAX_PAGES', 1000),
        'state_store' => env('TRANSLATION_SYNC_STATE_STORE', null),
    ],

    // 运行时翻译读取使用的本地缓存配置。
    'cache' => [
        'store' => env('TRANSLATION_CACHE_STORE', null),
        'ttl' => (int) env('TRANSLATION_CACHE_TTL', 86400 * 30),
    ],
];
