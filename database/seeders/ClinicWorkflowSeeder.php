<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Conversation;
use App\Models\DiscountType;
use App\Models\Feedback;
use App\Models\LensType;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VisitReason;
use App\Notifications\AppointmentStatusChanged;
use App\Notifications\BillingIssued;
use App\Notifications\OrderStatusChanged;
use Illuminate\Database\Seeder;

/**
 * Seeds end-to-end clinic workflow records for the defense demo.
 *
 * Demonstrates:
 *  - Appointment → confirmed with SMS record
 *  - Prescription linked to appointment
 *  - Prescription-gated order → confirmed → billing → partial payment
 *  - Non-prescription order → completed → billing → paid
 *  - Conversation with staff reply
 *  - Feedback on completed appointment
 */
class ClinicWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $customer = $this->demoCustomer();
        $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();

        $appointment = $this->seedAppointment($customer, $staff);
        $prescription = $this->seedPrescription($customer, $appointment, $staff);
        $this->seedPrescriptionOrder($customer, $appointment, $prescription, $staff);
        $this->seedNonPrescriptionOrder($customer);
        $this->seedConversation($customer, $staff, $appointment);
        $this->seedFeedback($customer, $appointment);
        $this->seedSampleNotifications($customer);
        $this->seedServiceRecord($customer, $appointment, $staff);
    }

    private function demoCustomer(): User
    {
        return User::query()->where('email', 'customer@eyecare.test')->firstOrFail();
    }

    private function seedAppointment(User $customer, User $staff): Appointment
    {
        $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
        $visitReason = VisitReason::query()->where('name', 'eye_exam')->firstOrFail();

        return Appointment::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'visit_reason_id' => $visitReason->id],
            [
                'staff_id' => $staff->id,
                'appointment_status_id' => $confirmedStatus->id,
                'scheduled_at' => now()->addDays(3)->setTime(10, 0),
                'contact_notes' => 'Please call an hour before. Mobile: +63 912 000 0001',
                'staff_notes' => 'First-time patient. Bring previous prescription if available.',
            ],
        );
    }

    private function seedPrescription(User $customer, Appointment $appointment, User $staff): Prescription
    {
        return Prescription::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'appointment_id' => $appointment->id],
            [
                'od_sphere' => -1.75,
                'od_cylinder' => -0.50,
                'od_axis' => 180,
                'os_sphere' => -2.00,
                'os_cylinder' => -0.75,
                'os_axis' => 175,
                'pd' => 63.5,
                'prescribed_at' => now()->subDays(7),
                'expires_at' => now()->addYear(),
                'notes' => 'Mild myopia with astigmatism. Recommend anti-reflective coating.',
                'created_by' => $staff->id,
            ],
        );
    }

    private function seedPrescriptionOrder(User $customer, Appointment $appointment, Prescription $prescription, User $staff): void
    {
        $variant = ProductVariant::query()->where('sku', 'CRF-BLK-001')->firstOrFail();
        $lensType = LensType::query()->where('name', 'single_vision')->firstOrFail();
        $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
        $seniorDiscount = DiscountType::query()->where('name', 'Senior Citizen')->firstOrFail();

        $unitPrice = (string) $variant->price;
        $subtotal = bcmul($unitPrice, '1', 2);
        $discountAmount = bcmul($subtotal, '0.20', 2); // 20% Senior Citizen
        $totalAmount = bcsub($subtotal, $discountAmount, 2);

        $order = Order::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'appointment_id' => $appointment->id, 'is_non_prescription' => false],
            [
                'order_number' => 'ORD-DEMO-0001',
                'prescription_id' => $prescription->id,
                'order_status_id' => $confirmedStatus->id,
                'subtotal' => $subtotal,
                'discount_type_id' => $seniorDiscount->id,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'confirmed_at' => now()->subDays(5),
            ],
        );

        OrderItem::query()->firstOrCreate(
            ['order_id' => $order->id, 'variant_sku' => $variant->sku],
            [
                'product_variant_id' => $variant->id,
                'lens_type_id' => $lensType->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'lens_type_name' => $lensType->name,
                'unit_price' => $unitPrice,
                'quantity' => 1,
                'subtotal' => $subtotal,
            ],
        );

        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();
        $billing = Billing::query()->firstOrCreate(
            ['billable_type' => Order::class, 'billable_id' => $order->id],
            [
                'billing_status_id' => $issuedStatus->id,
                'total_amount' => $totalAmount,
                'amount_paid' => '80.00',
                'balance_due' => bcsub($totalAmount, '80.00', 2),
                'issued_at' => now()->subDays(4),
            ],
        );

        $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();
        Payment::query()->firstOrCreate(
            ['billing_id' => $billing->id, 'reference_number' => 'GCX-DEMO-001'],
            [
                'payment_status_id' => $postedStatus->id,
                'payment_method_id' => PaymentMethod::query()->where('name', 'GCash')->value('id'),
                'amount' => '80.00',
                'notes' => 'Down payment via GCash.',
                'paid_at' => now()->subDays(4),
            ],
        );
    }

    private function seedNonPrescriptionOrder(User $customer): void
    {
        $variant = ProductVariant::query()->where('sku', 'RMF-GLD-001')->firstOrFail();
        $lensType = LensType::query()->where('name', 'single_vision')->firstOrFail();
        $completedStatus = OrderStatus::query()->where('name', 'completed')->firstOrFail();

        $unitPrice = (string) $variant->price;
        $subtotal = bcmul($unitPrice, '1', 2);

        $order = Order::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'is_non_prescription' => true, 'order_number' => 'ORD-DEMO-0002'],
            [
                'order_status_id' => $completedStatus->id,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'discount_amount' => 0,
                'confirmed_at' => now()->subDays(14),
                'completed_at' => now()->subDays(7),
            ],
        );

        OrderItem::query()->firstOrCreate(
            ['order_id' => $order->id, 'variant_sku' => $variant->sku],
            [
                'product_variant_id' => $variant->id,
                'lens_type_id' => $lensType->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'lens_type_name' => $lensType->name,
                'unit_price' => $unitPrice,
                'quantity' => 1,
                'subtotal' => $subtotal,
            ],
        );

        $paidStatus = BillingStatus::query()->where('name', 'paid')->firstOrFail();
        $billing = Billing::query()->firstOrCreate(
            ['billable_type' => Order::class, 'billable_id' => $order->id],
            [
                'billing_status_id' => $paidStatus->id,
                'total_amount' => $subtotal,
                'amount_paid' => $subtotal,
                'balance_due' => '0.00',
                'issued_at' => now()->subDays(13),
            ],
        );

        $postedStatus = PaymentStatus::query()->where('name', 'posted')->firstOrFail();
        Payment::query()->firstOrCreate(
            ['billing_id' => $billing->id, 'reference_number' => 'CASH-DEMO-001'],
            [
                'payment_status_id' => $postedStatus->id,
                'payment_method_id' => PaymentMethod::query()->where('name', 'Cash')->value('id'),
                'amount' => $subtotal,
                'notes' => 'Full cash payment.',
                'paid_at' => now()->subDays(13),
            ],
        );
    }

    private function seedSampleNotifications(User $customer): void
    {
        // Only seed if no notifications exist yet for this customer
        if ($customer->notifications()->count() > 0) {
            return;
        }

        $appointment = Appointment::query()
            ->where('customer_id', $customer->id)
            ->with('status')
            ->latest()
            ->first();

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->with('status')
            ->latest()
            ->first();

        $billing = Billing::query()
            ->where('billable_type', Order::class)
            ->whereHas('billable', fn ($q) => $q->where('customer_id', $customer->id))
            ->latest()
            ->first();

        if ($appointment) {
            $customer->notify(new AppointmentStatusChanged($appointment));
        }

        if ($order) {
            $customer->notify(new OrderStatusChanged($order));
        }

        if ($billing) {
            $customer->notify(new BillingIssued($billing));
        }
    }

    private function seedConversation(User $customer, User $staff, Appointment $appointment): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id],
        );

        if ($conversation->messages()->count() === 0) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $customer->id,
                'body' => 'Hi, I wanted to ask — do I need to bring anything specific for the eye exam?',
                'created_at' => now()->subDays(2),
            ]);

            $message->contextLinks()->create([
                'contextable_type' => Appointment::class,
                'contextable_id' => $appointment->id,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $staff->id,
                'body' => 'Hi! Please bring your current glasses or contact lenses if you have them, and any previous prescription. See you on your appointment date!',
                'created_at' => now()->subDays(2)->addHours(1),
            ]);
        }
    }

    private function seedFeedback(User $customer, Appointment $appointment): void
    {
        $completedStatus = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();

        // Seed a completed appointment for feedback (separate from the upcoming one)
        $completedAppointment = Appointment::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'appointment_status_id' => $completedStatus->id],
            [
                'visit_reason_id' => VisitReason::query()->where('name', 'follow_up')->firstOrFail()->id,
                'scheduled_at' => now()->subDays(10),
                'contact_notes' => 'Follow-up after prescription.',
            ],
        );

        Feedback::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'appointment_id' => $completedAppointment->id],
            [
                'rating' => 5,
                'comment' => 'Excellent service! The staff was very professional and thorough.',
                'staff_reply' => 'Thank you so much! We look forward to serving you again.',
                'replied_by' => User::query()->where('email', 'staff@eyecare.test')->value('id'),
                'replied_at' => now()->subDays(9),
            ],
        );
    }

    private function seedServiceRecord(User $customer, Appointment $appointment, User $staff): void
    {
        $service = Service::query()->where('name', 'Comprehensive Eye Exam')->firstOrFail();
        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        $serviceRecord = ServiceRecord::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'service_id' => $service->id, 'appointment_id' => $appointment->id],
            [
                'staff_id' => $staff->id,
                'amount' => $service->price,
                'discount_amount' => '0.00',
                'total_amount' => $service->price,
                'performed_at' => now()->subDays(3),
            ],
        );

        Billing::query()->firstOrCreate(
            ['billable_type' => ServiceRecord::class, 'billable_id' => $serviceRecord->id],
            [
                'billing_status_id' => $issuedStatus->id,
                'total_amount' => $service->price,
                'amount_paid' => '0.00',
                'balance_due' => $service->price,
                'issued_at' => now()->subDays(3),
            ],
        );
    }
}
