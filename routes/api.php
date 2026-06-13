<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StaffAppointmentController;
use App\Http\Controllers\Api\StaffOrderController;
use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::apiResource('prescriptions', PrescriptionController::class)->only(['index', 'show']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::get('billing/{billing}', [BillingController::class, 'show'])->name('billing.show');

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store']);
    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'indexMessages']);
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
    Route::get('attachments/{attachment}', [ConversationController::class, 'downloadAttachment'])->name('attachments.download');

    Route::post('feedback', [FeedbackController::class, 'store']);
    Route::get('feedback', [FeedbackController::class, 'index']);
    Route::get('feedback/{feedback}', [FeedbackController::class, 'show']);

    Route::prefix('staff')->middleware(EnsureUserIsStaff::class)->group(function (): void {
        Route::patch('appointments/{appointment}/status', [StaffAppointmentController::class, 'updateStatus']);
        Route::patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus']);
    });
});
