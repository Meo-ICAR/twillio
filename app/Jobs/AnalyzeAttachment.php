<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Services\Conversation\Reply;
use App\Services\Documents\DocumentChecker;
use App\Services\Documents\DocumentReader;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Legge un documento caricato con l'AI, lo confronta con i dati dichiarati e scrive all'agente l'esito.
 * Si lancia con dispatchAfterResponse(): parte dopo che WhatsApp ha ricevuto la risposta del webhook.
 */
class AnalyzeAttachment
{
    use Dispatchable;

    /** Documento (codice storico) => tipo di lettura. */
    private const LEGACY = ['documento_identita' => 'identita', 'codice_fiscale' => 'codice_fiscale', 'reddito' => 'reddito'];

    private const LABELS = [
        'documento_identita' => 'Documento d\'identità',
        'codice_fiscale' => 'Codice fiscale',
        'reddito' => 'Documento di reddito',
    ];

    public function __construct(private int $attachmentId) {}

    public function handle(DocumentReader $reader, DocumentChecker $checker, WhatsAppClient $client): void
    {
        // Parte dopo la risposta al webhook: il limite di 30 secondi del web non basta per leggere un documento.
        @set_time_limit(150);

        $attachment = Attachment::with(['loanRequest', 'praticaDocument.template'])->find($this->attachmentId);
        if (! $attachment || ! $reader->enabled()) {
            return;
        }

        // L'AI legge solo i documenti del catalogo con un tipo di lettura (o i tre storici, se il documento non è più in catalogo).
        $slot = $attachment->praticaDocument;
        $aiKind = $slot?->template ? $slot->template->ai_kind : (self::LEGACY[$attachment->kind] ?? null);
        if (! $aiKind) {
            return;
        }
        $readerKind = array_search($aiKind, self::LEGACY, true);

        $label = $slot?->name ?? self::LABELS[$attachment->kind] ?? $attachment->kind;
        $loan = $attachment->loanRequest;

        try {
            $fields = $reader->read($readerKind, $attachment->mime, Storage::disk('local')->get($attachment->path));
        } catch (\Throwable $e) {
            // Mai messaggi o contenuti nel log: possono contenere dati personali.
            Log::error('Lettura documento non riuscita', ['attachment' => $attachment->id, 'exception' => $e::class]);
            $fields = null;
        }

        if ($fields === null) {
            $attachment->update(['status' => 'non_analizzato']);
            $client->send($loan->agent_wa_number, Reply::text("ℹ️ {$label} ({$loan->code}): è stato comunque ricevuto, ma non sono riuscito a controllarlo in automatico. Lo vedrà l'istruttore."));

            return;
        }

        $issues = $checker->check($readerKind, $fields, $loan->personal ?? []);
        $status = match (true) {
            ! ($fields['legible'] ?? true) => 'non_leggibile',
            $issues !== [] => 'difforme',
            default => 'verificato',
        };
        $attachment->update(['status' => $status, 'analysis' => ['fields' => $fields, 'discrepancies' => $issues]]);

        // Esito sul documento della pratica: l'AI propone OK o rejected e lascia le annotazioni; l'operatore può correggere.
        if ($slot) {
            $slot->update(['status' => $issues === [] ? 'ok' : 'rejected', 'reviewed_at' => now()]);
            foreach ($issues === [] ? ['Controllo automatico: nessuna differenza con i dati inseriti.'] : $issues as $note) {
                $slot->addAnnotation('ai', $note);
            }
        }

        $text = $issues === []
            ? "✅ {$label} ({$loan->code}): controllo completato, nessuna differenza con i dati inseriti."
            : "⚠️ {$label} ({$loan->code}): ho trovato queste differenze:\n• ".implode("\n• ", $issues)
                ."\n\nPuoi inviare un documento corretto da Stato Pratiche → Carica documenti.";

        $client->send($loan->agent_wa_number, Reply::text($text));
    }
}
