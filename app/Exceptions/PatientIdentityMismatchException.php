<?php

namespace App\Exceptions;

use Exception;

class PatientIdentityMismatchException extends Exception
{
    public function __construct()
    {
        parent::__construct('The account details do not match the patient record. Contact the clinic for assistance.', 422);
    }
}
