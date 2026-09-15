<?php

namespace App\Exceptions;

use Exception;

class ActiveAppointmentExistsException extends Exception
{
    public function __construct(
        public readonly int $appointmentId,
        public readonly string $appointmentStatus,
    ) {
        parent::__construct('You already have an active appointment. You may reschedule or cancel it before requesting another.');
    }
}
