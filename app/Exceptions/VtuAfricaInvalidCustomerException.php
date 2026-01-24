<?php

namespace App\Exceptions;

use Exception;

class VtuAfricaInvalidCustomerException extends Exception
{
    protected ?array $errorData;

    public function __construct(string $message = 'Invalid Bet ID', ?array $errorData = null)
    {
        parent::__construct($message, 400);
        $this->errorData = $errorData;
    }

    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
}
