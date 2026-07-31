<?php

use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentRequestController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingRecordController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EyewearController;
use App\Http\Controllers\Api\FrameController;
use App\Http\Controllers\Api\FrameRatingController;
use App\Http\Controllers\Api\FrameReservationController;
use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\OtpChallengeController;
use App\Http\Controllers\Api\PatientIntakeController;
use App\Http\Controllers\Api\PatientLinkRequestController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\QuotationController;
use App\Models\AppointmentType;
use Illuminate\Support\Facades\Route;

// Public auth routes (versioned)
Route::prefix('v1')->middleware('throttle:login')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // New OTP-based authentication
    Route::post('auth/registration/otp', [OtpChallengeController::class, 'issue']);
    Route::post('auth/registration/verify', [OtpChallengeController::class, 'verify']);
    Route::post('auth/register', [AuthController::class, 'registerWithOtp']);
    Route::post('auth/login', [AuthController::class, 'patientLogin']);
    Route::post('auth/login/verify', [AuthController::class, 'patientLoginVerify']);
    Route::post('auth/password-recovery/otp', [OtpChallengeController::class, 'recoveryOtp']);
    Route::post('auth/password-recovery/verify', [OtpChallengeController::class, 'recoveryVerify']);
});

// Authenticated account-only routes (no active link required)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('logout-all', [AuthController::class, 'logoutAll']);
    Route::get('me', [AuthController::class, 'user']);
    Route::patch('me', [AuthController::class, 'update']);

    // Patient link requests
    Route::post('patient-link-requests', [PatientLinkRequestController::class, 'store']);
    Route::get('patient-link-requests/current', [PatientLinkRequestController::class, 'current']);

    // Appointment requests
    Route::get('appointment-requests', [AppointmentRequestController::class, 'index']);
    Route::post('appointment-requests', [AppointmentRequestController::class, 'store']);
    Route::get('appointment-requests/{appointmentRequest}', [AppointmentRequestController::class, 'show']);
    Route::post('appointment-requests/{appointmentRequest}/cancel', [AppointmentRequestController::class, 'cancel']);
});

// Authenticated clinical routes (active patient link required)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1', 'require.patient.link'])->group(function (): void {
    Route::get('appointment-types', fn () => response()->json([
        'data' => AppointmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes', 'requires_referral']),
    ]));
    Route::get('appointment-availability', AppointmentAvailabilityController::class);

    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);

    Route::get('appointments/{appointment}/intake', [PatientIntakeController::class, 'show']);
    Route::put('appointments/{appointment}/intake', [PatientIntakeController::class, 'upsert']);
    Route::post('appointments/{appointment}/intake/submit', [PatientIntakeController::class, 'submit']);

    Route::get('frames', [FrameController::class, 'index']);
    Route::get('frames/{frame}', [FrameController::class, 'show']);

    Route::get('frame-reservations', [FrameReservationController::class, 'index']);
    Route::post('frame-reservations', [FrameReservationController::class, 'store']);
    Route::post('frame-reservations/{reservation}/cancel', [FrameReservationController::class, 'cancel']);

    Route::get('prescriptions', [PrescriptionController::class, 'index']);
    Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show']);

    Route::get('quotations', [QuotationController::class, 'index']);
    Route::get('quotations/{quotation}', [QuotationController::class, 'show']);

    Route::get('job-orders', [JobOrderController::class, 'index']);
    Route::get('job-orders/{jobOrder}', [JobOrderController::class, 'show']);

    Route::get('billing-records', [BillingRecordController::class, 'index']);
    Route::get('billing-records/{billingRecord}', [BillingRecordController::class, 'show']);

    Route::get('eyewear', [EyewearController::class, 'index']);
    Route::get('eyewear/{key}', [EyewearController::class, 'show']);

    Route::get('conversation', [ConversationController::class, 'show']);
    Route::get('conversation/messages', [ConversationController::class, 'indexMessages']);
    Route::post('conversation/messages', [ConversationController::class, 'storeMessage']);

    Route::get('conversation/attachments/{attachment}', [ConversationController::class, 'downloadAttachment'])->name('conversation.attachments.download');

    Route::post('job-order-items/{item}/rating', [FrameRatingController::class, 'store']);
});
