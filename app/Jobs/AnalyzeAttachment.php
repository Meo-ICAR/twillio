<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Services\Conversation\Reply;
use App\Services\Documents\AnalysisOutcome;
use App\Services\Documents\DocumentPipeline;
use App\Services\Documents\DocumentReader;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Analizza un documento caricato (controlli sui documenti) e scrive all'agente l'esito.
 * Si lancia con dispatchAfterResponse(): parte dopo che WhatsApp ha ricevuto la risposta del webhook.
 */
class AnalyzeAttachment
{
    use Dispatchable;

    /**
     * @param  array<int,string|array<string,mixed>>|null  $checks  controlli agganciati al passo; null = predefiniti del tipo
     */
    public function __construct(private int $attachmentId, private ?array $checks = null) {}

    public function handle(DocumentPipeline $pipeline, DocumentReader $reader, WhatsAppClient $client): void
    {
        // Parte dopo la risposta al webhook: il limite di 30 secondi del web non basta per leggere un documento.
        @set_time_limit(150);

        $attachment = Attachment::with(['loanRequest', 'praticaDocument.template'])->find($this->attachmentId);
        if (! $attachment || ! $reader->enabled()) {
            return;
        }

        $outcome = $pipeline->run($attachment, $this->checks);
        if (! $outcome) {
            return;
        }

        $client->send($attachment->loanRequest->agent_wa_number, Reply::text($this->message($outcome)));
    }

    private function message(AnalysisOutcome $outcome): string
    {
        $code = $outcome->attachment->loanRequest->code;
        $label = $outcome->label;

        if ($outcome->status === 'non_analizzato') {
            return "ℹ️ {$label} ({$code}): è stato comunque ricevuto, ma non sono riuscito a controllarlo in automatico. Lo vedrà l'istruttore.";
        }

        if ($outcome->aiKind === 'informativa') {
            return $outcome->passed()
                ? "✅ {$label} ({$code}): è il nostro modulo ed è firmato."
                : "⚠️ {$label} ({$code}): non la posso accettare.\n• ".implode("\n• ", $outcome->issues);
        }

        return $outcome->passed()
            ? "✅ {$label} ({$code}): controllo completato, nessuna differenza con i dati inseriti."
            : "⚠️ {$label} ({$code}): ho trovato queste differenze:\n• ".implode("\n• ", $outcome->issues)
                ."\n\nPuoi inviare un documento corretto da Stato Pratiche → Carica documenti.";
    }
}
