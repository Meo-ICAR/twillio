<?php

namespace App\Mail;

use App\Models\LoanRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Conferma al produttore che la pratica è stata perfezionata e inviata in istruttoria. */
class PerfezionamentoMail extends Mailable
{
    public function __construct(public LoanRequest $loan) {}

    public function envelope(): Envelope
    {
        $product = LoanRequest::productLabels()[$this->loan->product] ?? $this->loan->product;

        return new Envelope(subject: "Pratica {$this->loan->code} perfezionata · {$product}".($this->loan->is_test ? ' (PROVA)' : ''));
    }

    public function content(): Content
    {
        $lines = ["La pratica {$this->loan->code} è stata perfezionata e inviata in istruttoria.", ''];
        foreach (LoanRequest::describe($this->loan->answers, 'richiesta') as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        return new Content(htmlString: '<pre style="font:14px/1.5 monospace">'.e(implode("\n", $lines)).'</pre>');
    }
}
