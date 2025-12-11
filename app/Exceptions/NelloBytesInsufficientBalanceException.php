<?php

namespace App\Exceptions;

class NelloBytesInsufficientBalanceException extends NelloBytesApiException
{
    public function __construct(string $message = 'Insufficient wallet balance', $errorData = null)
    {
        parent::__construct($message, 'INSUFFICIENT_WALLET_BALANCE', $errorData, 402);
    }
}

