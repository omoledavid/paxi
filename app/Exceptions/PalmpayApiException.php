<?php

namespace App\Exceptions;

use Exception;

class PalmpayApiException extends Exception
{
    protected string $errorCode;

    protected ?array $errorData;

    public function __construct(
        string $message = 'PalmPay API error',
        string $errorCode = '',
        ?array $errorData = null,
        int $httpCode = 500
    ) {
        parent::__construct($message, $httpCode);
        $this->errorCode = $errorCode;
        $this->errorData = $errorData;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
}
