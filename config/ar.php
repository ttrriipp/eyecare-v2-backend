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
            'label' => 'Tortoise rectangle v2 preset',
            'calibration' => [
                'frame_width_mm' => 138.0,
                'outer_frame_height_mm' => 45.0,
                'lens_width_mm' => 54.0,
                'lens_height_mm' => 40.0,
                'bridge_width_mm' => 18.0,
                'temple_length_mm' => 145.0,
                'scale' => ['x' => 0.185, 'y' => 0.185, 'z' => 0.185],
                'anchor' => ['x' => -0.0075, 'y' => 0.026, 'z' => 0.0],
                'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ],
        ],
    ],
];
