<?php

use App\Http\Controllers\MessageAttachmentPreviewController;
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

    Route::get('/pdf/prescriptions/{prescription}', function (Prescription $prescription, PdfService $pdf) {
        abort_unless(Auth::user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless($prescription->isCurrentVersion(), 403);

        return $pdf->prescriptionPrintout($prescription);
    })->name('pdf.prescription');

    Route::get('/pdf/prescriptions/{prescription}/card', function (Prescription $prescription, PdfService $pdf) {
        abort_unless(Auth::user()?->canAccessPanel(Filament::getDefaultPanel()), 403);
        abort_unless($prescription->isCurrentVersion(), 403);

        return $pdf->prescriptionCard($prescription);
    })->name('pdf.prescription.card');
});
