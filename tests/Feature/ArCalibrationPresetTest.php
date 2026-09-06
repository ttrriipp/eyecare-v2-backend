<?php

test('the active calibration preset matches the tortoise v2 fixture', function (): void {
    expect(config('ar.presets.round_frame.label'))->toBe('Tortoise rectangle v2 preset')
        ->and(config('ar.presets.round_frame.calibration'))->toMatchArray([
            'frame_width_mm' => 138.0,
            'outer_frame_height_mm' => 45.0,
            'lens_width_mm' => 54.0,
            'lens_height_mm' => 40.0,
            'bridge_width_mm' => 18.0,
            'temple_length_mm' => 145.0,
            'scale' => ['x' => 0.185, 'y' => 0.185, 'z' => 0.185],
            'anchor' => ['x' => -0.0075, 'y' => 0.026, 'z' => 0.0],
            'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ]);
});
