<?php

namespace App\Mail;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewFeedbackNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $feedback;
    public $user;

    public function __construct(Feedback $feedback, User $user)
    {
        $this->feedback = $feedback;
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Feedback Submitted - ' . $this->feedback->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-feedback',
            with: [
                'feedback' => $this->feedback,
                'user' => $this->user,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
