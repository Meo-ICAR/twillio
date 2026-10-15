<?php

namespace App\Services\Documents;

use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Bpm\BpmEvents;
use App\Services\Conversation\Reply;
use App\Services\Crm\LoanEmailSender;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Unico\Core\Models\Document;

/**
 * Chiude una pratica quando tutti i suoi documenti sono firmati: stato «da perfezionare», mail con i firmati,
 * evento BPM e avviso all'installatore. Una sola volta (blocco sulla riga della pratica), da chiamare sia dal listener
 * di firma sia dal comando periodico che recupera le firme completate quasi insieme o con un webhook perso.
 */
class LoanSignatureFinalizer
{
    /** @return bool true se questa chiamata ha chiuso la pratica */
    public function finalize(LoanRequest $loan): bool
    {
        $documents = [];

        $closed = DB::transaction(function () use ($loan, &$documents) {
            $locked = LoanRequest::lockForUpdate()->find($loan->id);
            if (! $locked || $locked->signed_at) {
                return false;
            }

            $documents = Document::where('documentable_type', 'loan_request')->where('documentable_id', $locked->id)->get()->all();
            if ($documents === [] || collect($documents)->contains(fn (Document $d) => ! $d->is_signed)) {
                return false;
            }

            $locked->update(['signature_status' => 'firmata', 'signed_at' => now(), 'status' => 'perfezionare']);

            return true;
        });

        if (! $closed) {
            return false;
        }

        $loan->refresh();
        $this->mailSigned($loan, Company::forWhatsApp($loan->agent_wa_number), $documents);
        BpmEvents::dispatchPerfected($loan);
        $this->notifyAgent($loan);

        return true;
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
