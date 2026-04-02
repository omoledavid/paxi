<?php

namespace App\Exceptions;

use Exception;

class PalmpayInvalidCustomerException extends Exception
{
    protected ?array $errorData;

    public function __construct(string $message = 'Invalid customer', ?array $errorData = null)
    {
        parent::__construct($message, 400);
        $this->errorData = $errorData;
    }

    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
}
