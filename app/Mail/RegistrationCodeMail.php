<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public int $validMinutes = 10) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your SmackChat verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration-code');
    }
}
