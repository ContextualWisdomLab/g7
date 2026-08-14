<?php

$checks = array_map(
    static fn (string $check): string => trim($check),
    explode(',', (string) env('READINESS_CHECKS', 'database,cache,storage')),
);

return [
    'checks' => $checks,
    'storage_path' => env('READINESS_STORAGE_PATH', storage_path('framework')),
];
