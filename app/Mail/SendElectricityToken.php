<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendElectricityToken extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $token;

    public $amount;

    public $meterNo;

    public $transactionRef;

    /**
     * Create a new message instance.
     */
    public function __construct($token, $amount, $meterNo, $transactionRef)
    {
        $this->token = $token;
        $this->amount = $amount;
        $this->meterNo = $meterNo;
        $this->transactionRef = $transactionRef;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Electricity Token Purchase Successful',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.electricity_token',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
