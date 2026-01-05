<?php

namespace App\Exceptions;

use Exception;

class NelloBytesTransactionFailedException extends Exception
{
    protected $apiResponse;

    /**
     * Create a new exception instance.
     *
     * @param  string  $message
     * @param  array  $apiResponse
     * @return void
     */
    public function __construct(string $message = 'Transaction failed', array $apiResponse = [])
    {
        parent::__construct($message);
        $this->apiResponse = $apiResponse;
    }

    /**
     * Get the API response associated with the exception.
     *
     * @return array
     */
    public function getApiResponse(): array
    {
        return $this->apiResponse;
    }
}
