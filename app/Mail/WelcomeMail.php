<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $purpose,
        public ?string $displayName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: [
                'email' => $this->email,
                'purpose' => $this->purpose,
                'displayName' => $this->displayName,
                'brand' => config('app.name'),
            ],
        );
    }
}
