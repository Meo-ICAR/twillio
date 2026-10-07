<?php

namespace App\Services\Documents;

use App\Models\Attachment;
use App\Models\PraticaField;
use App\Services\Checks\CheckRegistry;
use App\Services\Checks\DocumentCheck;
use App\Services\Checks\DocumentContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Esegue sui documenti caricati i controlli (DocumentCheck) agganciati al passo del dialogo, oppure quelli
 * predefiniti del tipo di documento (config 'document_checks'). Legge il documento con l'AI una sola volta.
 * Porta il documento della pratica a OK o rejected, con le annotazioni dell'AI.
 */
class DocumentPipeline
{
    /** Tipo di lettura => codice del documento usato da lettore e confronti. */
    private const KIND_CODES = ['identita' => 'documento_identita', 'codice_fiscale' => 'codice_fiscale', 'reddito' => 'reddito', 'informativa' => 'informativa'];

    private const LABELS = [
        'documento_identita' => 'Documento d\'identità',
        'codice_fiscale' => 'Codice fiscale',
        'reddito' => 'Documento di reddito',
        'informativa' => 'Informativa privacy',
    ];

    public function __construct(private DocumentReader $reader, private CheckRegistry $registry) {}

    /**
     * @param  array<int,string|array<string,mixed>>|null  $checks  controlli agganciati al passo; null = predefiniti del tipo
     * @return AnalysisOutcome|null null se il documento non ha un tipo di lettura o la lettura non è attiva
     */
    public function run(Attachment $attachment, ?array $checks = null): ?AnalysisOutcome
    {
        if (! $this->reader->enabled()) {
            return null;
        }

        // Si legge solo ciò che ha un tipo di lettura: dal catalogo, o dai codici storici se il documento non c'è più.
        $slot = $attachment->praticaDocument;
        $aiKind = $slot?->template ? $slot->template->ai_kind : (array_search($attachment->kind, self::KIND_CODES, true) ?: null);
        if (! $aiKind || ! isset(self::KIND_CODES[$aiKind])) {
            return null;
        }

        $readerKind = self::KIND_CODES[$aiKind];
        $label = $slot?->name ?? self::LABELS[$readerKind];
        $loan = $attachment->loanRequest;

        // Dati personali all'AI solo dopo l'informativa verificata: il documento aspetta e riparte quando lo è.
        if ($aiKind !== 'informativa' && ! $loan->privacy_verified_at) {
            $attachment->update(['status' => 'in_attesa_informativa', 'pending_checks' => $checks]);

            return new AnalysisOutcome($attachment, 'in_attesa', $label, $aiKind);
        }

        // Dati noti con cui confrontare: quelli dichiarati dall'agente e quelli già letti dagli altri documenti.
        $declared = array_replace(PraticaField::knownValues($loan, $slot?->code ?? $attachment->kind), array_filter($loan->personal ?? [], 'filled'));

        $context = new DocumentContext($attachment, $loan, $readerKind, function () use ($attachment, $readerKind) {
            try {
                return $this->reader->read($readerKind, $attachment->mime, Storage::disk('local')->get($attachment->path));
            } catch (\Throwable $e) {
                // Mai messaggi o contenuti nel log: possono contenere dati personali.
                Log::error('Lettura documento non riuscita', ['attachment' => $attachment->id, 'exception' => $e::class]);

                return null;
            }
        }, $declared);

        if ($context->fields() === null) {
            $attachment->update(['status' => 'non_analizzato']);

            return new AnalysisOutcome($attachment, 'non_analizzato', $label, $aiKind);
        }

        [$issues, $proposals] = $this->inspect($context, $checks ?? config("finanziamento.document_checks.{$aiKind}", []));
        $fields = $context->fields();

        $status = match (true) {
            $issues === [] => 'verificato',
            ! ($fields['legible'] ?? true) => 'non_leggibile',
            default => 'difforme',
        };
        $attachment->update(['status' => $status, 'pending_checks' => null, 'analysis' => ['fields' => $fields, 'discrepancies' => $issues]]);

        // Esito sul documento della pratica: l'AI propone OK o rejected e lascia le annotazioni; l'operatore può correggere.
        if ($slot) {
            $slot->update(['status' => $issues === [] ? 'ok' : 'rejected', 'reviewed_at' => now()]);
            foreach ($issues === [] ? ['Controllo automatico: nessun problema trovato.'] : $issues as $note) {
                $slot->addAnnotation('ai', $note);
            }
        }

        if ($issues === [] && $proposals) {
            PraticaField::propose($loan, $proposals, $attachment);
        }

        return new AnalysisOutcome($attachment, $status, $label, $aiKind, $issues, $issues === [] ? $proposals : []);
    }

    /**
     * @param  array<int,string|array<string,mixed>>  $entries
     * @return array{0: list<string>, 1: array<string,string>} problemi (si ferma al primo controllo che fallisce) e dati proposti
     */
    private function inspect(DocumentContext $context, array $entries): array
    {
        $proposals = [];

        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;
            $check = $this->registry->get($name);

            if (! $check instanceof DocumentCheck) {
                Log::error('Controllo documento sconosciuto o non adatto ai documenti', ['check' => $name]);

                continue;
            }

            $result = $check->inspect($context->withParams(is_array($entry) ? array_diff_key($entry, ['name' => 1]) : []));
            if (! $result->passed()) {
                return [$result->issues, []];
            }
            $proposals = $result->proposals + $proposals;
        }

        return [[], $proposals];
    }
}
