<?php

namespace App\Events;

use App\Models\NelloBytesTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NelloBytesTransactionUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public NelloBytesTransaction $transaction;

    public array $webhookPayload;

    /**
     * Create a new event instance.
     */
    public function __construct(NelloBytesTransaction $transaction, array $webhookPayload = [])
    {
        $this->transaction = $transaction;
        $this->webhookPayload = $webhookPayload;
    }
}
