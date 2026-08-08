<?php

namespace Database\Seeders;

use App\Enums\BillingRecordStatus;
use App\Enums\ComplaintStatus;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds end-to-end clinic workflow records.
 *
 * Demonstrates:
 *  - Appointment → check-in → encounter → prescription
 *  - Quotation → accepted → job order → dispensing → billing record
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
        $billingRecord = $this->seedBillingRecord($patient, $jobOrder, $staff);
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
        $scheduled = AppointmentStatus::query()->where('name', 'scheduled')->firstOrFail();
        $appointmentType = AppointmentType::query()->where('name', 'Routine Check-up')->firstOrFail();

        // Scheduled appointment (no encounter yet)
        Appointment::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'appointment_type_id' => $appointmentType->id],
            [
                'appointment_number' => 'APT-2026-000001',
                'created_by' => $staff->id,
                'appointment_status_id' => $scheduled->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'scheduled_at' => now()->addDays(3)->setTime(10, 0),
                'contact_notes' => 'First-time patient. Bring previous prescription if available.',
            ],
        );

        // Fulfilled appointment (with encounter)
        $fulfilled = AppointmentStatus::query()->where('name', 'fulfilled')->firstOrFail();
        $appointment = Appointment::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'appointment_number' => 'APT-2026-000002'],
            [
                'created_by' => $staff->id,
                'appointment_status_id' => $fulfilled->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'scheduled_at' => now()->subDays(30)->setTime(14, 0),
                'fulfilled_at' => now()->subDays(30)->setTime(15, 0),
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
                'prescription_number' => 'RX-2026-000001',
                // Main group
                'main_od_sphere' => -1.75,
                'main_od_cylinder' => -0.50,
                'main_os_sphere' => -2.00,
                'main_os_cylinder' => -0.75,
                // ADD group (populated for this example)
                'add_od_sphere' => 1.50,
                'add_od_cylinder' => -0.25,
                'add_os_sphere' => 1.25,
                'add_os_cylinder' => -0.50,
                'remarks' => 'Mild myopia with astigmatism. Recommend anti-reflective coating.',
                'prescribed_at' => now()->subDays(1),
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
                'eyewear_key' => 'eyw_'.Str::ulid(),
                'prescription_id' => $prescription->id,
                'status' => QuotationStatus::Accepted,
                'valid_until' => now()->addDays(14),
                'subtotal' => 7500,
                'discount_amount' => 0,
                'total' => 7500,
                'presented_by' => $staff->id,
                'presented_at' => now()->subDays(1),
                'confirmed_by' => $staff->id,
                'confirmed_at' => now()->subDays(1)->addMinutes(30),
                'notes' => 'Includes anti-reflective coating and scratch-resistant treatment.',
            ],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_id' => $quotation->id, 'description' => 'Classic Frame — Matte Black'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_id' => $quotation->id, 'description' => 'Progressive Lens with AR Coating'],
            ['quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        );

        return $quotation;
    }

    private function seedJobOrder(Patient $patient, Encounter $encounter, Prescription $prescription, Quotation $quotation, User $staff): JobOrder
    {
        $jobOrder = JobOrder::query()->firstOrCreate(
            ['quotation_id' => $quotation->id],
            [
                'job_order_number' => 'ORD-2026-000001',
                'eyewear_key' => $quotation->eyewear_key,
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

    private function seedBillingRecord(Patient $patient, JobOrder $jobOrder, User $staff): BillingRecord
    {
        $billingRecord = BillingRecord::query()->firstOrCreate(
            ['job_order_id' => $jobOrder->id],
            [
                'billing_record_number' => 'BR-2026-000001',
                'patient_id' => $patient->id,
                'encounter_id' => $jobOrder->encounter_id,
                'status' => BillingRecordStatus::PartiallyPaid,
                'total_amount' => 7500,
                'amount_paid' => 3000,
                'balance_due' => 4500,
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
            ],
        );

        BillingPayment::query()->firstOrCreate(
            ['billing_record_id' => $billingRecord->id, 'amount' => 3000],
            [
                'payment_method' => 'cash',
                'status' => 'posted',
                'recorded_by' => $staff->id,
                'notes' => 'Down payment at time of order.',
            ],
        );

        return $billingRecord;
    }

    private function seedConversation(Patient $patient, User $staff, Appointment $appointment): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['patient_id' => $patient->id],
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
