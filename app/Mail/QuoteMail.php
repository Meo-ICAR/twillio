<?php

namespace App\Mail;

use App\Models\LoanRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Dati della richiesta di preventivo (fase anonima), per l'istruttoria. */
class QuoteMail extends Mailable
{
    public function __construct(public LoanRequest $loan) {}

    public function envelope(): Envelope
    {
        $product = LoanRequest::productLabels()[$this->loan->product] ?? $this->loan->product;

        return new Envelope(subject: "Preventivo {$this->loan->code} · {$product}".($this->loan->is_test ? ' (PROVA)' : ''));
    }

    public function content(): Content
    {
        $lines = ["Richiesta di preventivo {$this->loan->code}", 'Agente (WhatsApp): '.$this->loan->agent_wa_number, ''];
        foreach (LoanRequest::describe($this->loan->answers, 'richiesta') as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        return new Content(htmlString: '<pre style="font:14px/1.5 monospace">'.e(implode("\n", $lines)).'</pre>');
    }
}
