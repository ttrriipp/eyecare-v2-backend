<?php

namespace App\Exceptions;

use Exception;

class AccessoryNotOrderableException extends Exception
{
    public function __construct()
    {
        parent::__construct('One or more selected accessories are no longer available to order.', 422);
    }
}
