<?php

namespace App\Enums;

enum PatientNotificationActionType: string
{
    case Appointment = 'appointment';
    case AppointmentRequest = 'appointment_request';
    case Prescription = 'prescription';
    case OpticalOrder = 'optical_order';
    case AccessoryOrderRequest = 'accessory_order_request';
    case Conversation = 'conversation';
}
