<?php

namespace App\Enums;

enum PatientNotificationKind: string
{
    case AppointmentConfirmed = 'appointment_confirmed';
    case AppointmentRequestDeclined = 'appointment_request_declined';
    case AppointmentRescheduled = 'appointment_rescheduled';
    case AppointmentCancelled = 'appointment_cancelled';
    case PrescriptionAvailable = 'prescription_available';
    case VisitCompleted = 'visit_completed';
    case OpticalOrderConfirmed = 'optical_order_confirmed';
    case OpticalOrderReady = 'optical_order_ready';
    case OpticalOrderCancelled = 'optical_order_cancelled';
    case OpticalOrderReleased = 'optical_order_released';
    case PaymentRecorded = 'payment_recorded';
    case PaymentUpdated = 'payment_updated';
    case NewMessage = 'new_message';
}
