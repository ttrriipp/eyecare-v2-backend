<?php

namespace App\Services;

use Illuminate\Support\Str;

final class SmsMessageFormatter
{
    public const BRAND_PREFIX = 'EyeCare: ';

    /**
     * Add the clinic identity to an SMS body unless it is already branded.
     */
    public static function brand(string $message): string
    {
        $normalizedMessage = Str::lower(ltrim($message));

        if (Str::startsWith($normalizedMessage, ['eyecare:', 'eyecare '])) {
            return $message;
        }

        return self::BRAND_PREFIX.$message;
    }
}
