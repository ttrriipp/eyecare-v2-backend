<?php

namespace App\Exceptions;

use Exception;

class AppointmentRescheduleRequestStateException extends Exception
{
    public const APPOINTMENT_NOT_RESCHEDULABLE = 'APPOINTMENT_NOT_RESCHEDULABLE';

    public const RESCHEDULE_REQUEST_ALREADY_PENDING = 'RESCHEDULE_REQUEST_ALREADY_PENDING';

    public const RESCHEDULE_REQUEST_NOT_CANCELLABLE = 'RESCHEDULE_REQUEST_NOT_CANCELLABLE';

    public const RESCHEDULE_REQUEST_NOT_APPROVABLE = 'RESCHEDULE_REQUEST_NOT_APPROVABLE';

    public const RESCHEDULE_REQUEST_SELECTION_INVALID = 'RESCHEDULE_REQUEST_SELECTION_INVALID';

    public const RESCHEDULE_REQUEST_STALE = 'RESCHEDULE_REQUEST_STALE';

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

    public static function notApprovable(): self
    {
        return new self(
            self::RESCHEDULE_REQUEST_NOT_APPROVABLE,
            'This reschedule request can no longer be approved.',
        );
    }

    public static function selectionInvalid(): self
    {
        return new self(
            self::RESCHEDULE_REQUEST_SELECTION_INVALID,
            'The selected time must be one of the submitted reschedule choices.',
        );
    }

    public static function stale(): self
    {
        return new self(
            self::RESCHEDULE_REQUEST_STALE,
            'The appointment changed before this reschedule request could be approved.',
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
