<?php

use Illuminate\Support\Facades\Route;

test('api server errors use a generic structured response even when debug is enabled', function (): void {
    $originalDebug = config('app.debug');
    config(['app.debug' => true]);

    try {
        Route::get('/api/v1/testing/server-error', function (): never {
            throw new RuntimeException(
                "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'step_up_token_consumed_at' in 'where clause'",
            );
        });

        $response = $this->getJson('/api/v1/testing/server-error');

        $response->assertInternalServerError()
            ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonPath('error.message', 'An unexpected server error occurred.');

        expect($response->getContent())
            ->not->toContain('SQLSTATE')
            ->not->toContain('eyecare_backend_v2')
            ->not->toContain('step_up_token_consumed_at');
    } finally {
        config(['app.debug' => $originalDebug]);
    }
});
