<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalletFunded extends Mailable
{
    use Queueable, SerializesModels;

    public string $firstName;
    public float  $amountReceived;
    public float  $amountCredited;
    public float  $newBalance;
    public string $payerName;
    public string $payerBank;
    public string $orderNo;

    public function __construct(
        string $firstName,
        float  $amountReceived,
        float  $amountCredited,
        float  $newBalance,
        string $payerName,
        string $payerBank,
        string $orderNo
    ) {
        $this->firstName      = $firstName;
        $this->amountReceived = $amountReceived;
        $this->amountCredited = $amountCredited;
        $this->newBalance     = $newBalance;
        $this->payerName      = $payerName;
        $this->payerBank      = $payerBank;
        $this->orderNo        = $orderNo;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wallet Funded Successfully',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.wallet_funded',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
