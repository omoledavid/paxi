<?php

namespace App\Exceptions;

class NelloBytesApiException extends NelloBytesException
{
    public function __construct(string $message = 'NelloBytes API error', string $errorCode = '', $errorData = null, int $httpCode = 500)
    {
        parent::__construct($message, $errorCode, $errorData, $httpCode);
    }
}

