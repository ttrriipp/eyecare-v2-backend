<?php

use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FrameController;
use App\Http\Controllers\Api\FrameRatingController;
use App\Http\Controllers\Api\FrameReservationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientIntakeController;
use App\Http\Controllers\Api\PatientProfileController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StaffAppointmentController;
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

    Route::get('patient/profile', [PatientProfileController::class, 'show']);
    Route::patch('patient/profile', [PatientProfileController::class, 'update']);

    Route::get('patient/intakes', [PatientIntakeController::class, 'index']);
    Route::post('patient/intakes', [PatientIntakeController::class, 'store']);
    Route::patch('patient/intakes/{intake}', [PatientIntakeController::class, 'update']);
    Route::post('patient/intakes/{intake}/submit', [PatientIntakeController::class, 'submit']);
    Route::post('patient/intakes/{intake}/verify', [PatientIntakeController::class, 'verify']);

    Route::get('appointments/availability', AppointmentAvailabilityController::class);
    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);
    Route::patch('appointments/{appointment}/contact-note', [AppointmentController::class, 'updateContactNote']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);
    Route::get('visit-reasons', fn () => response()->json(['data' => VisitReason::all(['id', 'name', 'duration_minutes'])]));
    Route::get('brands', fn () => response()->json(['data' => Brand::orderBy('name')->get(['id', 'name'])]));
    Route::get('categories', fn () => response()->json(['data' => ProductCategory::orderBy('name')->get(['id', 'name'])]));
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::apiResource('prescriptions', PrescriptionController::class)->only(['index', 'show']);
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
    });
});

// Versioned patient API (/api/v1)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('frames', [FrameController::class, 'index']);
    Route::get('frames/{product}', [FrameController::class, 'show']);

    Route::get('frame-reservations', [FrameReservationController::class, 'index']);
    Route::post('frame-reservations', [FrameReservationController::class, 'store']);
    Route::post('frame-reservations/{reservation}/cancel', [FrameReservationController::class, 'cancel']);

    Route::post('ratings', [FrameRatingController::class, 'store']);

    Route::get('quotations', [QuotationController::class, 'index']);
    Route::get('quotations/{quotation}', [QuotationController::class, 'show']);

    Route::get('job-orders', [JobOrderController::class, 'index']);
    Route::get('job-orders/{job_order}', [JobOrderController::class, 'show']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
});
