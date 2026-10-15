<?php

namespace App\Listeners;

use App\Models\LoanRequest;
use App\Services\Documents\LoanSignatureFinalizer;
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
        if ($loan) {
            app(LoanSignatureFinalizer::class)->finalize($loan);
        }
    }

    /** La pratica locale del documento: si cerca per id, perché la relazione morph partirebbe dalla connessione del core. */
    private function loan(?Document $document): ?LoanRequest
    {
        return $document?->documentable_type === 'loan_request' ? LoanRequest::find($document->documentable_id) : null;
    }
}
