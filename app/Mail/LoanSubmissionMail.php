<?php

namespace App\Mail;

use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment as MailAttachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Pratica con i suoi dati e gli allegati, per l'istruttoria. */
class LoanSubmissionMail extends Mailable
{
    public function __construct(public LoanRequest $loan) {}

    public function envelope(): Envelope
    {
        $product = LoanRequest::productLabels()[$this->loan->product] ?? $this->loan->product;

        return new Envelope(subject: "Pratica {$this->loan->code} · {$product}".($this->loan->is_test ? ' (PROVA)' : ''));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre style="font:14px/1.5 monospace">'.e($this->body()).'</pre>');
    }

    /** @return list<MailAttachment> */
    public function attachments(): array
    {
        return $this->loan->attachments()->get()
            ->filter(fn (Attachment $a) => \Storage::disk('local')->exists($a->path))
            ->map(fn (Attachment $a) => MailAttachment::fromStorageDisk('local', $a->path)
                ->as("{$a->kind}.".pathinfo($a->path, PATHINFO_EXTENSION))->withMime($a->mime))
            ->values()->all();
    }

    private function body(): string
    {
        $loan = $this->loan;
        $lines = ["Pratica {$loan->code}", 'Stato: '.(LoanRequest::STATUSES[$loan->status] ?? $loan->status), 'Agente (WhatsApp): '.$loan->agent_wa_number, ''];

        $sections = [
            'Richiesta' => LoanRequest::describe($loan->answers, 'richiesta'),
            'Dati del cliente' => LoanRequest::describe($loan->personal, 'perfezionamento'),
        ];
        foreach ($sections as $title => $values) {
            if ($values) {
                $lines[] = strtoupper($title);
                foreach ($values as $label => $value) {
                    $lines[] = "- {$label}: {$value}";
                }
                $lines[] = '';
            }
        }

        if ($difformita = $loan->personal['_difformita'] ?? null) {
            $lines[] = 'DATI DIFFORMI DA VERIFICARE';
            array_push($lines, ...array_map(fn ($d) => "- {$d}", $difformita));
            $lines[] = '';
        }

        $lines[] = 'DOCUMENTI';
        foreach (PraticaDocument::populate($loan) as $doc) {
            $lines[] = "- {$doc->name}: ".(PraticaDocument::STATUSES[$doc->status] ?? $doc->status);
        }

        return implode("\n", $lines);
    }
}
