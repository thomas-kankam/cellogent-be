<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AggregatorLowBalanceMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $balance
     */
    public function __construct(public array $balance) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SMS aggregator balance is low — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.aggregator-low-balance',
            with: [
                'balance' => $this->balance,
                'brand' => config('app.name'),
            ],
        );
    }
}
