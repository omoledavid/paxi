<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendEpin extends Mailable
{
    use Queueable, SerializesModels;

    public $epins;
    public $transactionRef;

    /**
     * Create a new message instance.
     */
    public function __construct($epins, $transactionRef)
    {
        $this->epins = $epins;
        $this->transactionRef = $transactionRef;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your EPIN Purchase Successful - ' . $this->transactionRef,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.epin_purchase',
            with: [
                'epins' => $this->epins,
                'transactionRef' => $this->transactionRef,
            ]
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
