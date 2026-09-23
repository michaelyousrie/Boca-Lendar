<?php

namespace App\Calendar;

use RuntimeException;

class CalendarException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
