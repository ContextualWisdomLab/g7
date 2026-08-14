<?php

$checks = array_values(array_filter(
    array_map(
        static fn (string $check): string => trim($check),
        explode(',', (string) env('READINESS_CHECKS', 'database,cache,storage')),
    ),
    static fn (string $check): bool => $check !== '',
));

return [
    'checks' => $checks,
    'storage_path' => env('READINESS_STORAGE_PATH', storage_path('framework')),
];
