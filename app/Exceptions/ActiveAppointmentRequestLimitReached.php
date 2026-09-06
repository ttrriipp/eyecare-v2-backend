<?php

namespace App\Exceptions;

use Exception;

class ActiveAppointmentRequestLimitReached extends Exception
{
    public function __construct(public readonly int $maxActiveRequests)
    {
        parent::__construct(
            "You have reached the maximum of {$maxActiveRequests} active appointment requests.",
            422,
        );
    }
}
