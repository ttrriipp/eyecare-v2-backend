<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffAppointmentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);

    Route::prefix('staff')->group(function (): void {
        Route::patch('appointments/{appointment}/status', [StaffAppointmentController::class, 'updateStatus']);
    });
});
