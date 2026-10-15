<?php

namespace App\Mail;

use App\Models\LoanRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** All'istruttoria: i documenti della pratica firmati dal cliente. */
class SignedDocumentsMail extends Mailable
{
    /** @param array<int,\Unico\Core\Models\Document> $documents */
    public function __construct(public LoanRequest $loan, public array $documents) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Documenti firmati · pratica {$this->loan->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signed-documents');
    }

    /** @return array<int,Attachment> */
    public function attachments(): array
    {
        return collect($this->documents)->map(function ($d) {
            $media = $d->currentMedia();

            return $media ? Attachment::fromData(fn () => (string) stream_get_contents($media->stream()), $media->file_name)->withMime($media->mime_type) : null;
        })->filter()->values()->all();
    }
}
