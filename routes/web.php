<?php

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Http\Controllers\MessageAttachmentDownloadController;
use App\Http\Controllers\MessageAttachmentPreviewController;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Services\PdfService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'database' => 'connected']);
    } catch (Throwable) {
        return response()->json(['status' => 'error', 'database' => 'disconnected'], 503);
    }
});

// ── Admin panel file responses (authenticated staff/admin only) ─────────────
Route::middleware(['auth', 'web'])->group(function () {
    Route::get('/attachments/{attachment}/preview', MessageAttachmentPreviewController::class)
        ->name('attachments.preview');

    Route::get('/attachments/{attachment}/download', MessageAttachmentDownloadController::class)
        ->name('attachments.download');

    Route::get('/pdf/prescriptions/{prescription}', function (Prescription $prescription, PdfService $pdf) {
        abort_unless(Auth::user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless($prescription->isCurrentVersion(), 403);

        return $pdf->prescriptionPrintout($prescription);
    })->name('pdf.prescription');

    Route::get('/encounters/{encounter}/print', function (Encounter $encounter) {
        abort_unless(Auth::user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless($encounter->status === EncounterStatus::Completed, 403);

        $encounter->load(['patient', 'appointment.appointmentType', 'optometrist', 'addenda.author', 'completedBy', 'prescriptions']);

        // Audit the print event
        app(CreateAuditLog::class)->handle(
            subject: $encounter,
            action: AuditEvent::EncounterPrinted->value,
            metadata: [
                'encounter_id' => $encounter->id,
                'appointment_id' => $encounter->appointment_id,
                'patient_id' => $encounter->patient_id,
                'actor_id' => Auth::id(),
            ],
        );

        return view('filament.encounters.print', [
            'encounter' => $encounter,
            'clinic' => config('clinic'),
        ]);
    })->name('encounters.print');
});
