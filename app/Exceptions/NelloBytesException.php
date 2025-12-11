<?php

namespace App\Exceptions;

use Exception;

class NelloBytesException extends Exception
{
    protected $errorCode;
    protected $errorData;

    public function __construct(string $message = '', string $errorCode = '', $errorData = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->errorData = $errorData;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorData()
    {
        return $this->errorData;
    }
}

