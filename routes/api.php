<?php

use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentRequestAvailabilityController;
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
use App\Http\Controllers\Api\PatientInvitationController;
use App\Http\Controllers\Api\PatientLinkRequestController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

// Public auth routes (versioned)
Route::prefix('v1')->middleware('throttle:login')->group(function (): void {
    // Registration flow
    Route::post('auth/registration/otp', [OtpChallengeController::class, 'issue']);
    Route::post('auth/registration/verify', [AuthController::class, 'registrationVerify']);
    Route::post('auth/register', [AuthController::class, 'registerWithOtp']);

    // Login flow
    Route::post('auth/login', [AuthController::class, 'patientLogin']);
    Route::post('auth/login/verify', [AuthController::class, 'patientLoginVerify']);

    // Password recovery
    Route::post('auth/password-recovery/otp', [AuthController::class, 'recoveryOtp']);
    Route::post('auth/password-recovery/verify', [AuthController::class, 'recoveryVerify']);

    // Policy metadata
    Route::get('auth/policies', [AuthController::class, 'policies']);
});

// Authenticated account-only routes (no active link required)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('logout-all', [AuthController::class, 'logoutAll']);
    Route::get('me', [AuthController::class, 'user']);
    Route::patch('me', [AuthController::class, 'update']);

    // Step-up OTP for sensitive changes
    Route::post('auth/step-up/otp', [AuthController::class, 'requestStepUp']);
    Route::post('auth/step-up/verify', [AuthController::class, 'verifyStepUp']);
    Route::post('auth/password', [AuthController::class, 'changePassword'])
        ->middleware('require.step-up');

    // Contact management - read is free, mutations require step-up
    Route::get('account/contacts', [AuthController::class, 'listContacts']);
    Route::post('account/contacts/otp', [AuthController::class, 'requestContactOtp'])
        ->middleware('require.step-up');
    Route::post('account/contacts/verify', [AuthController::class, 'verifyContact']);
    Route::patch('account/contacts/{contact}/primary', [AuthController::class, 'setPrimaryContact'])
        ->middleware('require.step-up');
    Route::delete('account/contacts/{contact}', [AuthController::class, 'removeContact'])
        ->middleware('require.step-up');

    // Link state
    Route::get('account/link', [AuthController::class, 'linkStatus']);

    // Patient link requests
    Route::post('patient-link-requests', [PatientLinkRequestController::class, 'store']);
    Route::get('patient-link-requests/current', [PatientLinkRequestController::class, 'current']);

    // Appointment requests
    Route::get('appointment-request-availability', AppointmentRequestAvailabilityController::class);
    Route::get('appointment-requests', [AppointmentRequestController::class, 'index']);
    Route::post('appointment-requests', [AppointmentRequestController::class, 'store']);
    Route::get('appointment-requests/{appointmentRequest}', [AppointmentRequestController::class, 'show']);
    Route::post('appointment-requests/{appointmentRequest}/cancel', [AppointmentRequestController::class, 'cancel']);

    // Patient invitations
    Route::post('patient-invitations/acceptance/otp', [PatientInvitationController::class, 'requestOtp']);
    Route::post('patient-invitations/accept', [PatientInvitationController::class, 'accept']);

    // Frame catalog browsing does not require a linked patient record.
    Route::get('frames', [FrameController::class, 'index']);
    Route::get('frames/{frame}', [FrameController::class, 'show']);
});

// Authenticated clinical routes (active patient link required)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1', 'require.patient.link'])->group(function (): void {
    Route::get('appointment-availability', AppointmentAvailabilityController::class);

    // Confirmed appointments only (no direct booking - use appointment requests)
    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);

    Route::get('frame-reservations', [FrameReservationController::class, 'index']);
    Route::post('frame-reservations', [FrameReservationController::class, 'store']);
    Route::post('frame-reservations/{reservation}/cancel', [FrameReservationController::class, 'cancel']);

    Route::get('prescriptions', [PrescriptionController::class, 'index']);
    Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show']);

    Route::get('quotations', [QuotationController::class, 'index']);
    Route::get('quotations/{quotation}', [QuotationController::class, 'show']);

    Route::get('optical-orders', [OpticalOrderController::class, 'index']);
    Route::get('optical-orders/{jobOrder}', [OpticalOrderController::class, 'show']);

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

    Route::post('optical-order-items/{item}/rating', [FrameRatingController::class, 'store']);
    Route::post('job-order-items/{item}/rating', [FrameRatingController::class, 'store']);
});
