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

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $email,
        public string $purpose,
        public ?string $displayName = null,
        public array $meta = [],
    ) {}

    public function envelope(): Envelope
    {
        $brand = config('app.name');

        $subject = match ($this->purpose) {
            'client_onboarded' => "Your {$brand} account is ready",
            'admin_onboarded' => "Your {$brand} admin account is ready",
            default => "Welcome to {$brand}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return new Content(
            view: 'emails.welcome',
            with: [
                'email' => $this->email,
                'purpose' => $this->purpose,
                'displayName' => $this->displayName,
                'brand' => config('app.name'),
                'loginUrl' => $this->meta['login_url'] ?? ($frontend.'/login'),
                'accountType' => $this->meta['account_type'] ?? null,
                'businessName' => $this->meta['business_name'] ?? null,
            ],
        );
    }
}
