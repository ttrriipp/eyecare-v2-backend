<?php

namespace App\Exceptions;

use Exception;

class ActiveOrderRequestExistsException extends Exception
{
    public function __construct()
    {
        parent::__construct('You already have a pending accessory order request.', 422);
    }
}
