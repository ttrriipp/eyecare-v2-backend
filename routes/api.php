<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StaffAppointmentController;
use App\Http\Controllers\Api\StaffOrderController;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\VisitReason;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::get('visit-reasons', fn () => response()->json(['data' => VisitReason::all(['id', 'name', 'duration_minutes'])]));
    Route::get('brands', fn () => response()->json(['data' => Brand::orderBy('name')->get(['id', 'name'])]));
    Route::get('categories', fn () => response()->json(['data' => ProductCategory::orderBy('name')->get(['id', 'name'])]));
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::apiResource('prescriptions', PrescriptionController::class)->only(['index', 'show']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::get('billing/{billing}', [BillingController::class, 'show'])->name('billing.show');
    Route::get('billing/{billing}/pdf', [BillingController::class, 'receipt'])->name('billing.pdf');

    Route::get('conversations', [ConversationController::class, 'show']);
    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'indexMessages']);
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
    Route::post('conversations/{conversation}/messages/read', [ConversationController::class, 'markRead']);
    Route::get('attachments/{attachment}', [ConversationController::class, 'downloadAttachment'])->name('attachments.download');

    Route::post('feedback', [FeedbackController::class, 'store']);
    Route::get('feedback', [FeedbackController::class, 'index']);
    Route::get('feedback/{feedback}', [FeedbackController::class, 'show']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/mark-read', [NotificationController::class, 'markRead']);

    Route::prefix('staff')->middleware(EnsureUserIsStaff::class)->group(function (): void {
        Route::patch('appointments/{appointment}/status', [StaffAppointmentController::class, 'updateStatus']);
        Route::patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus']);
    });
});
