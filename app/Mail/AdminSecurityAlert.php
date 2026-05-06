<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminSecurityAlert extends Mailable
{
    use Queueable, SerializesModels;

    public string $alertSubject;
    public array $context;

    public function __construct(string $subject, array $context = [])
    {
        $this->alertSubject = $subject;
        $this->context = $context;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Security] ' . $this->alertSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_security_alert',
            with: [
                'alertSubject' => $this->alertSubject,
                'context' => $this->context,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
