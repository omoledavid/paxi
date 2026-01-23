<?php

namespace App\Exceptions;

use Exception;

class PaystackTransactionFailedException extends Exception
{
    protected $response;

    public function __construct($message = '', $response = [], $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse()
    {
        return $this->response;
    }
}
