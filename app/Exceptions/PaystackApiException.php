<?php

namespace App\Exceptions;

use Exception;

class PaystackApiException extends Exception
{
    protected $data;

    protected $errorCode;

    public function __construct($message = '', $errorCode = '', $data = [], $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }
}
