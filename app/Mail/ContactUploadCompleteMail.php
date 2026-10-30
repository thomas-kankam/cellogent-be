<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactUploadCompleteMail extends Mailable
{
    /**
     * @param  array{group_name: string, inserted: int, skipped: int, failed: int, total: int}  $stats
     */
    public function __construct(
        public string $email,
        public array $stats,
        public ?string $displayName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Contact upload finished — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-upload-complete',
            with: [
                'email' => $this->email,
                'displayName' => $this->displayName,
                'stats' => $this->stats,
                'brand' => config('app.name'),
            ],
        );
    }
}
