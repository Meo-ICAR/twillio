<?php

namespace App\Listeners;

use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Bpm\BpmEvents;
use App\Services\Conversation\Reply;
use App\Services\Crm\LoanEmailSender;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Events\SignatureSent;
use Unico\Core\Models\Document;

/** Collega gli eventi di firma del core alla pratica di unicoagent. */
class HandleSignatureEvents
{
    public function onSent(SignatureSent $event): void
    {
        $loan = $this->loan($event->request->document);
        if ($loan && ! $loan->signed_at) {
            $loan->update(['signature_status' => 'in_attesa']);
        }
    }

    public function onCancelled(SignatureCancelled $event): void
    {
        $loan = $this->loan($event->request->document);
        if (! $loan || $loan->signed_at) {
            return;
        }

        $loan->update(['signature_status' => match ($event->request->status) {
            SignatureRequestStatus::Declined => 'rifiutata',
            SignatureRequestStatus::Failed => 'errore',
            default => 'scaduta',
        }]);
    }

    public function onCompleted(SignatureCompleted $event): void
    {
        $loan = $this->loan($event->document);
        if (! $loan || $loan->signed_at) {
            return;
        }

        $documents = Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->get();
        if ($documents->contains(fn (Document $d) => ! $d->is_signed)) {
            return;
        }

        $company = Company::forWhatsApp($loan->agent_wa_number);
        $loan->update(['signature_status' => 'firmata', 'signed_at' => now(), 'status' => 'perfezionare']);

        $this->mailSigned($loan, $company, $documents->all());
        BpmEvents::dispatchPerfected($loan);
        $this->notifyAgent($loan);
    }

    /** La pratica locale del documento: si cerca per id, perché la relazione morph partirebbe dalla connessione del core. */
    private function loan(?Document $document): ?LoanRequest
    {
        return $document?->documentable_type === 'loan_request' ? LoanRequest::find($document->documentable_id) : null;
    }

    private function mailSigned(LoanRequest $loan, ?Company $company, array $documents): void
    {
        $to = app(LoanEmailSender::class)->recipient($company);
        if (! $to) {
            Log::warning('Documenti firmati: manca la casella dell\'istruttoria', ['loan' => $loan->code]);

            return;
        }

        try {
            Mail::to($to)->send(new SignedDocumentsMail($loan, $documents));
        } catch (\Throwable $e) {
            Log::error('Invio documenti firmati non riuscito', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }

    /** L'avviso parte solo se WhatsApp lo accetta (finestra di 24 ore aperta); un errore non blocca nulla. */
    private function notifyAgent(LoanRequest $loan): void
    {
        try {
            app(WhatsAppClient::class)->send($loan->agent_wa_number, Reply::text("✅ Pratica *{$loan->code}*: il cliente ha firmato i documenti. È pronta per il perfezionamento."));
        } catch (\Throwable $e) {
            Log::warning('Avviso di firma non inviato', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }
}
