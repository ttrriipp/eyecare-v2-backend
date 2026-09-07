<?php

namespace App\Exceptions;

use Exception;

class AppointmentRescheduleRequestStateException extends Exception
{
    public const APPOINTMENT_NOT_RESCHEDULABLE = 'APPOINTMENT_NOT_RESCHEDULABLE';

    public const RESCHEDULE_REQUEST_ALREADY_PENDING = 'RESCHEDULE_REQUEST_ALREADY_PENDING';

    public const RESCHEDULE_REQUEST_NOT_CANCELLABLE = 'RESCHEDULE_REQUEST_NOT_CANCELLABLE';

    public const SLOT_UNAVAILABLE = 'SLOT_UNAVAILABLE';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message, 422);
    }

    public static function appointmentNotReschedulable(): self
    {
        return new self(
            self::APPOINTMENT_NOT_RESCHEDULABLE,
            'This appointment is no longer available for a reschedule request.',
        );
    }

    public static function alreadyPending(): self
    {
        return new self(
            self::RESCHEDULE_REQUEST_ALREADY_PENDING,
            'This appointment already has a pending reschedule request.',
        );
    }

    public static function notCancellable(): self
    {
        return new self(
            self::RESCHEDULE_REQUEST_NOT_CANCELLABLE,
            'This reschedule request can no longer be withdrawn.',
        );
    }

    public static function slotUnavailable(): self
    {
        return new self(
            self::SLOT_UNAVAILABLE,
            'One or more requested time slots are no longer available.',
        );
    }
}
