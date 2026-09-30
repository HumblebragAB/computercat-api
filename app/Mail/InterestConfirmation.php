<?php

namespace App\Mail;

use App\Models\InterestSignup;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InterestConfirmation extends Mailable
{
    public function __construct(
        public InterestSignup $signup,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bekräfta din anmälan till '.$this->signup->game->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.interest-confirmation',
            with: [
                'spel' => $this->signup->game->name,
                'sprak' => mb_strtolower(InterestSignup::SPRAK[$this->signup->language] ?? 'ditt språk'),
                'bekrafta' => route('interest.confirm', $this->token),
                'avregistrera' => route('interest.unsubscribe', $this->token),
            ],
        );
    }
}
