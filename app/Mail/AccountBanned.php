<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountBanned extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account Suspended - Security Alert',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account_banned',
            with: [
                'user' => $this->user,
                'reason' => $this->user->banned_reason,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
