<?php

namespace App\Exceptions;

use Exception;

class OtpRateLimitReached extends Exception
{
    public readonly int $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds)
    {
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);

        parent::__construct('Too many verification attempts. Please try again later.', 429);
    }
}
