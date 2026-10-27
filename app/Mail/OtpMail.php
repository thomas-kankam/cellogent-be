<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $otp,
        public string $purpose,
        public ?string $displayName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->purpose === 'registration'
            ? 'Verify your '.config('app.name').' account'
            : 'Your '.config('app.name').' login code';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'email' => $this->email,
                'otp' => $this->otp,
                'purpose' => $this->purpose,
                'displayName' => $this->displayName,
                'brand' => config('app.name'),
                'expires' => (int) config('otp.expires_minutes', 10),
                'loginUrl' => config('app.frontend_url'),
            ],
        );
    }
}
