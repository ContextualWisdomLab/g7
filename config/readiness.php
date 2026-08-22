<?php

$checks = array_map(
    static fn (string $check): string => trim($check),
    explode(',', (string) env('READINESS_CHECKS', 'database,cache,storage')),
);

return [
    'checks' => $checks,
    'cache_store' => env('READINESS_CACHE_STORE') ?: env('CACHE_STORE', 'database'),
    'storage_path' => env('READINESS_STORAGE_PATH', storage_path('framework')),
];
