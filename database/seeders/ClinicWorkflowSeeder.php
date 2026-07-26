<?php

namespace Database\Seeders;

use App\Enums\ComplaintStatus;
use App\Enums\EncounterStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Database\Seeder;

/**
 * Seeds end-to-end clinic workflow records.
 *
 * Demonstrates:
 *  - Appointment → check-in → encounter → prescription
 *  - Quotation → accepted → job order → dispensing → invoice
 *  - Frame reservation flow
 *  - Complaint restart workflow
 *  - Conversation with staff reply
 */
class ClinicWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $patient = $this->demoPatient();
        $admin = User::query()->where('email', 'admin@eyecare.test')->firstOrFail();
        $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();

        $appointment = $this->seedAppointment($patient, $staff);
        $encounter = $this->seedEncounter($patient, $appointment, $admin);
        $prescription = $this->seedPrescription($patient, $encounter, $admin);
        $quotation = $this->seedQuotation($patient, $encounter, $prescription, $staff);
        $jobOrder = $this->seedJobOrder($patient, $encounter, $prescription, $quotation, $staff);
        $invoice = $this->seedInvoice($patient, $jobOrder, $staff);
        $this->seedConversation($patient, $staff, $appointment);
        $this->seedComplaint($patient, $jobOrder, $staff);
    }

    private function demoPatient(): Patient
    {
        $user = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

        return Patient::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function seedAppointment(Patient $patient, User $staff): Appointment
    {
        $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
        $visitReason = VisitReason::query()->where('name', 'Eye Exam')->firstOrFail();

        $appointment = Appointment::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'visit_reason_id' => $visitReason->id],
            [
                'appointment_number' => 'APT-2026-000001',
                'created_by' => $staff->id,
                'appointment_status_id' => $confirmed->id,
                'scheduled_at' => now()->addDays(3)->setTime(10, 0),
                'contact_notes' => 'First-time patient. Bring previous prescription if available.',
            ],
        );

        // Create a completed appointment for history
        $completed = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();
        Appointment::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'appointment_number' => 'APT-2026-000002'],
            [
                'created_by' => $staff->id,
                'appointment_status_id' => $completed->id,
                'visit_reason_id' => $visitReason->id,
                'scheduled_at' => now()->subDays(30)->setTime(14, 0),
                'completed_at' => now()->subDays(30)->setTime(15, 0),
            ],
        );

        return $appointment;
    }

    private function seedEncounter(Patient $patient, Appointment $appointment, User $optometrist): Encounter
    {
        return Encounter::query()->firstOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'encounter_number' => 'ENC-000001',
                'patient_id' => $patient->id,
                'optometrist_id' => $optometrist->id,
                'status' => EncounterStatus::Completed,
                'started_at' => now()->subDays(1),
                'completed_at' => now()->subDays(1)->addHour(),
            ],
        );
    }

    private function seedPrescription(Patient $patient, Encounter $encounter, User $optometrist): Prescription
    {
        return Prescription::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'encounter_id' => $encounter->id],
            [
                'od_sphere' => -1.75,
                'od_cylinder' => -0.50,
                'od_axis' => 180,
                'os_sphere' => -2.00,
                'os_cylinder' => -0.75,
                'os_axis' => 175,
                'pd' => 63.5,
                'prescribed_at' => now()->subDays(1),
                'expires_at' => now()->addYear(),
                'notes' => 'Mild myopia with astigmatism. Recommend anti-reflective coating.',
                'created_by' => $optometrist->id,
            ],
        );
    }

    private function seedQuotation(Patient $patient, Encounter $encounter, Prescription $prescription, User $staff): Quotation
    {
        $quotation = Quotation::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'encounter_id' => $encounter->id],
            [
                'quotation_number' => 'QUO-000001',
                'prescription_id' => $prescription->id,
                'status' => QuotationStatus::Accepted,
                'valid_until' => now()->addDays(14),
                'notes' => 'Includes anti-reflective coating and scratch-resistant treatment.',
            ],
        );

        $revision = QuotationRevision::query()->firstOrCreate(
            ['quotation_id' => $quotation->id, 'revision_number' => 1],
            [
                'subtotal' => 7500,
                'discount_amount' => 0,
                'total' => 7500,
                'presented_by' => $staff->id,
                'presented_at' => now()->subDays(1),
                'accepted_by' => $staff->id,
                'accepted_at' => now()->subDays(1)->addMinutes(30),
            ],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_revision_id' => $revision->id, 'description' => 'Classic Frame — Matte Black'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_revision_id' => $revision->id, 'description' => 'Progressive Lens with AR Coating'],
            ['quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        );

        return $quotation;
    }

    private function seedJobOrder(Patient $patient, Encounter $encounter, Prescription $prescription, Quotation $quotation, User $staff): JobOrder
    {
        $revision = $quotation->latestRevision;

        $jobOrder = JobOrder::query()->firstOrCreate(
            ['quotation_revision_id' => $revision->id],
            [
                'job_order_number' => 'JO-2026-000001',
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'prescription_id' => $prescription->id,
                'status' => JobOrderStatus::ReadyForDispensing,
                'total_amount' => 7500,
                'started_at' => now()->subDay(),
                'ready_at' => now(),
            ],
        );

        JobOrderItem::query()->firstOrCreate(
            ['job_order_id' => $jobOrder->id, 'description' => 'Classic Frame — Matte Black'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500],
        );

        JobOrderItem::query()->firstOrCreate(
            ['job_order_id' => $jobOrder->id, 'description' => 'Progressive Lens with AR Coating'],
            ['quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        );

        return $jobOrder;
    }

    private function seedInvoice(Patient $patient, JobOrder $jobOrder, User $staff): Invoice
    {
        $invoice = Invoice::query()->firstOrCreate(
            ['job_order_id' => $jobOrder->id],
            [
                'invoice_number' => 'INV-2026-000001',
                'patient_id' => $patient->id,
                'encounter_id' => $jobOrder->encounter_id,
                'status' => InvoiceStatus::PartiallyPaid,
                'subtotal' => 7500,
                'total' => 7500,
                'amount_paid' => 3000,
                'balance_due' => 4500,
                'sold_to_name' => $patient->full_name,
                'issued_at' => now(),
                'recorded_by' => $staff->id,
            ],
        );

        InvoiceItem::query()->firstOrCreate(
            ['invoice_id' => $invoice->id, 'description' => 'Classic Frame — Matte Black'],
            ['type' => 'product', 'quantity' => 1, 'unit_price' => 2500, 'amount' => 2500],
        );

        InvoiceItem::query()->firstOrCreate(
            ['invoice_id' => $invoice->id, 'description' => 'Progressive Lens with AR Coating'],
            ['type' => 'product', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        );

        InvoicePayment::query()->firstOrCreate(
            ['invoice_id' => $invoice->id, 'amount' => 3000],
            [
                'payment_method' => 'cash',
                'status' => 'posted',
                'recorded_by' => $staff->id,
                'notes' => 'Down payment at time of order.',
            ],
        );

        return $invoice;
    }

    private function seedConversation(Patient $patient, User $staff, Appointment $appointment): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['customer_id' => $patient->user_id],
        );

        Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'body' => 'Looking forward to my appointment!'],
            ['sender_id' => $patient->user_id],
        );

        Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'body' => 'Welcome! We look forward to seeing you.'],
            ['sender_id' => $staff->id, 'read_at' => now()],
        );
    }

    private function seedComplaint(Patient $patient, JobOrder $jobOrder, User $staff): void
    {
        Complaint::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'original_job_order_id' => $jobOrder->id],
            [
                'status' => ComplaintStatus::Open,
                'patient_description' => 'Lens coating appears uneven in certain lighting conditions.',
                'complaint_date' => now(),
                'created_by' => $staff->id,
            ],
        );
    }
}
