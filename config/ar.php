<?php

return [
    'assets' => [
        'quarantine_disk' => env('AR_QUARANTINE_DISK', 'ar_quarantine'),
        'published_disk' => env('AR_PUBLISHED_DISK', 'ar_published'),
        'base_url' => env('AR_ASSET_BASE_URL'),
        'public_prefix' => 'ar/variants',
        'max_bytes' => 10 * 1024 * 1024,
        'max_triangles' => 100000,
        'max_texture_dimension' => 2048,
    ],
    'presets' => [
        'round_frame' => [
            'label' => 'Current round-frame preset',
            'calibration' => [
                'frame_width_mm' => 123.0,
                'outer_frame_height_mm' => 48.0,
                'lens_width_mm' => 50.0,
                'lens_height_mm' => 45.0,
                'bridge_width_mm' => 20.0,
                'temple_length_mm' => 140.0,
                'scale' => ['x' => 0.1968, 'y' => 0.231304, 'z' => 0.1968],
                'anchor' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ],
        ],
    ],
];
