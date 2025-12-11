<?php

namespace App\Exceptions;

class NelloBytesInvalidCustomerException extends NelloBytesApiException
{
    public function __construct(string $message = 'Invalid customer ID', $errorData = null)
    {
        parent::__construct($message, 'INVALID_CUSTOMERID', $errorData, 400);
    }
}

