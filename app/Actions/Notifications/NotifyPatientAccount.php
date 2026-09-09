<?php

namespace App\Actions\Notifications;

use App\Actions\Sms\QueuePatientSms;
use App\Enums\PatientNotificationActionType;
use App\Enums\PatientNotificationKind;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Conversation;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Message;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\PatientDatabaseNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotifyPatientAccount
{
    public function __construct(private readonly QueuePatientSms $queuePatientSms) {}

    public function appointmentConfirmed(AppointmentRequest $request, Appointment $appointment): void
    {
        $this->handleAccount($request->user, new PatientDatabaseNotification(
            kind: PatientNotificationKind::AppointmentConfirmed,
            title: 'Appointment Confirmed',
            body: "Your appointment {$appointment->appointment_number} is confirmed for {$appointment->scheduled_at->format('M d, Y g:i A')}.",
            icon: 'heroicon-o-calendar-days',
            status: 'success',
            mobileActionType: PatientNotificationActionType::Appointment,
            mobileActionId: $appointment->id,
            actionUrl: "/appointments/{$appointment->id}",
            relatedType: 'appointment',
            relatedId: $appointment->id,
            eventKey: "appointment.confirmed:{$request->id}",
            patientId: $appointment->patient_id,
        ));
    }

    public function appointmentRequestDeclined(AppointmentRequest $request): void
    {
        $this->handleAccount($request->user, new PatientDatabaseNotification(
            kind: PatientNotificationKind::AppointmentRequestDeclined,
            title: 'Appointment Request Declined',
            body: "Your appointment request {$request->request_number} was not approved. Open the request for its current status.",
            icon: 'heroicon-o-calendar-days',
            status: 'warning',
            mobileActionType: PatientNotificationActionType::AppointmentRequest,
            mobileActionId: $request->id,
            actionUrl: "/appointment-requests/{$request->id}",
            relatedType: 'appointment_request',
            relatedId: $request->id,
            eventKey: "appointment_request.declined:{$request->id}",
        ));
    }

    public function appointmentRescheduled(Appointment $appointment): void
    {
        $rescheduleId = $appointment->latestReschedule()->value('id');

        $this->handlePatient($appointment->patient, new PatientDatabaseNotification(
            kind: PatientNotificationKind::AppointmentRescheduled,
            title: 'Appointment Rescheduled',
            body: "Your appointment {$appointment->appointment_number} is now scheduled for {$appointment->scheduled_at->format('M d, Y g:i A')}.",
            icon: 'heroicon-o-calendar-days',
            status: 'warning',
            mobileActionType: PatientNotificationActionType::Appointment,
            mobileActionId: $appointment->id,
            actionUrl: "/appointments/{$appointment->id}",
            relatedType: 'appointment',
            relatedId: $appointment->id,
            eventKey: "appointment.rescheduled:{$rescheduleId}",
            patientId: $appointment->patient_id,
        ));
    }

    public function appointmentCancelled(Appointment $appointment): void
    {
        $message = "Your appointment {$appointment->appointment_number} scheduled for {$appointment->scheduled_at->format('M d, Y g:i A')} was cancelled by the clinic.";

        $this->handlePatient($appointment->patient, new PatientDatabaseNotification(
            kind: PatientNotificationKind::AppointmentCancelled,
            title: 'Appointment Cancelled',
            body: $message,
            icon: 'heroicon-o-calendar-days',
            status: 'danger',
            mobileActionType: PatientNotificationActionType::Appointment,
            mobileActionId: $appointment->id,
            actionUrl: "/appointments/{$appointment->id}",
            relatedType: 'appointment',
            relatedId: $appointment->id,
            eventKey: "appointment.cancelled:{$appointment->id}",
            patientId: $appointment->patient_id,
        ));

        $this->queuePatientSms->handle(
            patient: $appointment->patient,
            event: 'appointment_cancelled',
            message: $message,
            appointment: $appointment,
        );
    }

    public function consultationCompleted(Encounter $encounter): void
    {
        $prescription = $encounter->prescriptions()->latest('id')->first();
        $appointment = $encounter->appointment;

        if ($prescription !== null) {
            $notification = new PatientDatabaseNotification(
                kind: PatientNotificationKind::PrescriptionAvailable,
                title: 'Prescription Available',
                body: 'Your updated prescription is now available in the app.',
                icon: 'heroicon-o-document-text',
                status: 'success',
                mobileActionType: PatientNotificationActionType::Prescription,
                mobileActionId: $prescription->id,
                actionUrl: "/prescriptions/{$prescription->id}",
                relatedType: 'prescription',
                relatedId: $prescription->id,
                eventKey: "prescription.available:{$prescription->id}",
                patientId: $encounter->patient_id,
            );
        } else {
            $notification = new PatientDatabaseNotification(
                kind: PatientNotificationKind::VisitCompleted,
                title: 'Visit Completed',
                body: 'Your clinic visit is complete. Your appointment record has been updated.',
                icon: 'heroicon-o-check-circle',
                status: 'success',
                mobileActionType: $appointment !== null ? PatientNotificationActionType::Appointment : null,
                mobileActionId: $appointment?->id,
                actionUrl: $appointment !== null ? "/appointments/{$appointment->id}" : null,
                relatedType: 'appointment',
                relatedId: $appointment?->id,
                eventKey: "visit.completed:{$encounter->id}",
                patientId: $encounter->patient_id,
            );
        }

        $this->handlePatient($encounter->patient, $notification);
    }

    public function opticalOrderConfirmed(JobOrder $order): void
    {
        $message = "Your optical order {$order->job_order_number} has been confirmed.";

        $this->handlePatient($order->patient, $this->orderNotification(
            order: $order,
            title: 'Optical Order Confirmed',
            body: $message,
            status: 'success',
            event: 'confirmed',
            kind: PatientNotificationKind::OpticalOrderConfirmed,
        ));

        $this->queuePatientSms->handle(
            patient: $order->patient,
            event: 'optical_order_confirmed',
            message: $message,
            jobOrder: $order,
        );
    }

    public function opticalOrderReady(JobOrder $order): void
    {
        $message = "Your optical order {$order->job_order_number} is ready for pickup.";

        $this->handlePatient($order->patient, $this->orderNotification(
            order: $order,
            title: 'Order Ready for Pickup',
            body: $message,
            status: 'success',
            event: 'ready',
            kind: PatientNotificationKind::OpticalOrderReady,
        ));

        $this->queuePatientSms->handle(
            patient: $order->patient,
            event: 'optical_order_ready',
            message: $message,
            jobOrder: $order,
        );
    }

    public function opticalOrderCancelled(JobOrder $order): void
    {
        $message = "Your optical order {$order->job_order_number} was cancelled.";

        $this->handlePatient($order->patient, $this->orderNotification(
            order: $order,
            title: 'Optical Order Cancelled',
            body: $message,
            status: 'danger',
            event: 'cancelled',
            kind: PatientNotificationKind::OpticalOrderCancelled,
        ));

        $this->queuePatientSms->handle(
            patient: $order->patient,
            event: 'optical_order_cancelled',
            message: $message,
            jobOrder: $order,
        );
    }

    public function opticalOrderReleased(JobOrder $order): void
    {
        $message = "Your optical order {$order->job_order_number} has been released.";

        $this->handlePatient($order->patient, $this->orderNotification(
            order: $order,
            title: 'Order Released',
            body: $message,
            status: 'success',
            event: 'released',
            kind: PatientNotificationKind::OpticalOrderReleased,
        ));

        $this->queuePatientSms->handle(
            patient: $order->patient,
            event: 'optical_order_released',
            message: $message,
            jobOrder: $order,
        );
    }

    public function paymentRecorded(BillingPayment $payment): void
    {
        $billingRecord = $payment->billingRecord()->with(['patient', 'jobOrder'])->first();

        if ($billingRecord === null) {
            return;
        }

        $this->handlePatient($billingRecord->patient, $this->paymentNotification(
            billingRecord: $billingRecord,
            title: 'Payment Recorded',
            body: sprintf(
                'A payment of PHP %s was recorded for billing record %s.',
                number_format((float) $payment->amount, 2),
                $billingRecord->billing_record_number,
            ),
            eventKey: "payment.recorded:{$payment->id}",
            kind: PatientNotificationKind::PaymentRecorded,
        ));
    }

    public function paymentUpdated(BillingPayment $payment, int $originalPaymentId): void
    {
        $billingRecord = $payment->billingRecord()->with(['patient', 'jobOrder'])->first();

        if ($billingRecord === null) {
            return;
        }

        $this->handlePatient($billingRecord->patient, $this->paymentNotification(
            billingRecord: $billingRecord,
            title: 'Payment Updated',
            body: "A recorded payment for billing record {$billingRecord->billing_record_number} was updated.",
            eventKey: "payment.updated:{$originalPaymentId}",
            kind: PatientNotificationKind::PaymentUpdated,
        ));
    }

    public function messageReceived(Message $message, Conversation $conversation): void
    {
        $this->handleAccount($conversation->account, new PatientDatabaseNotification(
            kind: PatientNotificationKind::NewMessage,
            title: 'New Message',
            body: 'The clinic sent you a new message.',
            icon: 'heroicon-o-chat-bubble-left-ellipsis',
            status: 'info',
            mobileActionType: PatientNotificationActionType::Conversation,
            mobileActionId: null,
            actionUrl: '/conversation',
            relatedType: 'conversation',
            relatedId: $conversation->id,
            eventKey: "message.received:{$message->id}",
        ));
    }

    public function handlePatient(?Patient $patient, PatientDatabaseNotification $notification): void
    {
        if ($patient === null) {
            return;
        }

        $account = $patient->account()->first();

        if ($account !== null) {
            $this->handleAccount($account, $notification);
        }
    }

    public function handleAccount(?User $account, PatientDatabaseNotification $notification): void
    {
        if ($account === null) {
            return;
        }

        DB::afterCommit(function () use ($account, $notification): void {
            try {
                $recipient = User::query()->find($account->id);

                if ($recipient === null || $recipient->notifications()->where('data->event_key', $notification->eventKey)->exists()) {
                    return;
                }

                $recipient->notify($notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function orderNotification(
        JobOrder $order,
        string $title,
        string $body,
        string $status,
        string $event,
        PatientNotificationKind $kind,
    ): PatientDatabaseNotification {
        return new PatientDatabaseNotification(
            kind: $kind,
            title: $title,
            body: $body,
            icon: 'heroicon-o-shopping-bag',
            status: $status,
            mobileActionType: PatientNotificationActionType::OpticalOrder,
            mobileActionId: $order->id,
            actionUrl: "/optical-orders/{$order->id}",
            relatedType: 'optical_order',
            relatedId: $order->id,
            eventKey: "optical_order.{$event}:{$order->id}",
            patientId: $order->patient_id,
        );
    }

    private function paymentNotification(
        BillingRecord $billingRecord,
        string $title,
        string $body,
        string $eventKey,
        PatientNotificationKind $kind,
    ): PatientDatabaseNotification {
        $order = $billingRecord->jobOrder;

        return new PatientDatabaseNotification(
            kind: $kind,
            title: $title,
            body: $body,
            icon: 'heroicon-o-banknotes',
            status: 'success',
            mobileActionType: $order !== null ? PatientNotificationActionType::OpticalOrder : null,
            mobileActionId: $order?->id,
            actionUrl: $order !== null ? "/optical-orders/{$order->id}" : null,
            relatedType: $order !== null ? 'optical_order' : 'billing_record',
            relatedId: $order?->id ?? $billingRecord->id,
            eventKey: $eventKey,
            patientId: $billingRecord->patient_id,
        );
    }
}
