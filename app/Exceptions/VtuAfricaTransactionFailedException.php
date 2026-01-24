<?php

namespace App\Exceptions;

use Exception;

class VtuAfricaTransactionFailedException extends Exception
{
    protected ?array $responseData;

    public function __construct(string $message = 'VTU Africa transaction failed', ?array $responseData = null)
    {
        parent::__construct($message, 422);
        $this->responseData = $responseData;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
