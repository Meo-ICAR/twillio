<?php

namespace App\Models;

use App\Jobs\AnalyzeAttachment;
use App\Services\Flows\FlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LoanRequest extends Model
{
    public const STATUSES = [
        'richiesta' => 'Richiesta',
        'in_attesa_informativa' => 'In attesa di informativa',
        'informativa_ricevuta' => 'Informativa ricevuta',
        'perfezionata' => 'Perfezionata',
    ];

    /** Dopo quanti minuti un documento ancora in analisi smette di far aspettare il dialogo (lavoro perso o AI lenta). */
    public const ANALYSIS_WAIT_MINUTES = 15;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Eliminare una pratica (dal pannello o per scadenza) cancella tutto ciò che la riguarda:
        // i file dei clienti e i dati ancora presenti nelle conversazioni aperte (le righe collegate a documenti,
        // allegati e annotazioni spariscono con la pratica).
        static::deleting(function (self $loan) {
            Conversation::where('loan_request_id', $loan->id)->where('status', 'attiva')->get()
                ->each(fn (Conversation $conv) => $conv->update(['status' => 'annullata', 'data' => []]));

            Storage::disk('local')->deleteDirectory("pratiche/{$loan->code}");
        });
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'is_test' => 'boolean',
            'personal' => 'encrypted:array',
            'privacy_received_at' => 'datetime',
            'privacy_verified_at' => 'datetime',
            'perfected_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function praticaDocuments(): HasMany
    {
        return $this->hasMany(PraticaDocument::class);
    }

    /**
     * Ci sono documenti caricati da poco e non ancora controllati dall'AI (o in attesa dell'informativa verificata).
     *
     * @param  bool  $analysisActive  la lettura dei documenti è attiva: senza, nessuno li controlla e non si aspetta nulla
     */
    public function hasPendingAnalyses(bool $analysisActive): bool
    {
        if (! $analysisActive) {
            return false;
        }

        return $this->attachments()
            ->whereIn('status', ['ricevuto', 'in_attesa_informativa'])
            ->where('received_at', '>=', now()->subMinutes(self::ANALYSIS_WAIT_MINUTES))
            ->where(fn ($q) => $q
                ->whereHas('praticaDocument.template', fn ($t) => $t->whereNotNull('ai_kind'))
                ->orWhere(fn ($q) => $q->whereNull('pratica_document_id')->whereIn('kind', ['informativa', 'documento_identita', 'codice_fiscale', 'reddito'])))
            ->exists();
    }

    /** L'informativa è stata inviata e non è stata rifiutata: non serve chiederla di nuovo. */
    public function hasInformativa(): bool
    {
        return $this->privacy_received_at !== null
            && ! $this->praticaDocuments()->where('code', 'informativa')->where('status', 'rejected')->exists();
    }

    /** Dati letti dall'AI dai documenti, da far confermare all'agente. */
    public function fields(): HasMany
    {
        return $this->hasMany(PraticaField::class);
    }

    /**
     * L'informativa è a posto (nostro modulo, firmato): da qui i documenti si possono leggere.
     * Quelli arrivati prima, e rimasti in attesa, vengono analizzati ora.
     */
    public function verifyPrivacy(): void
    {
        if (! $this->privacy_verified_at) {
            $this->update(['privacy_verified_at' => now()]);
        }

        $this->attachments()->where('status', 'in_attesa_informativa')->get()
            ->each(fn (Attachment $a) => AnalyzeAttachment::dispatchAfterResponse($a->id, $a->pending_checks));
    }

    /** L'informativa è stata rifiutata: i documenti già letti restano, quelli nuovi aspetteranno un'informativa valida. */
    public function revokePrivacy(): void
    {
        $this->update(['privacy_verified_at' => null]);
    }

    /**
     * L'istruttore chiede un documento integrativo: dal catalogo (indicando il codice) o libero (solo il nome).
     * Se il documento c'è già sulla pratica lo si riusa: la nuova nota si aggiunge alle precedenti.
     */
    public function requestIntegrativeDocument(?string $catalogCode, string $name, string $note, int $userId): PraticaDocument
    {
        $template = $catalogCode
            ? FinanziamentoDocument::where('product', $this->product)->where('code', $catalogCode)->first()
            : null;

        $slot = $this->praticaDocuments()->firstOrCreate(
            ['code' => $template?->code ?? Str::slug($name)],
            [
                'name' => $template?->name ?? $name,
                'requirement' => 'integrativo',
                'finanziamento_document_id' => $template?->id,
                'sort_order' => 900,
            ],
        );
        $slot->requestIntegration($note, $userId);

        return $slot->fresh();
    }

    /** Tutti i documenti obbligatori sono OK e non c'è nessuna integrazione richiesta e non ancora ricevuta. */
    public function documentsComplete(): bool
    {
        $slots = $this->praticaDocuments()->get();
        $required = $slots->where('requirement', 'obbligatorio');

        return $required->isNotEmpty()
            && $required->every(fn (PraticaDocument $d) => $d->status === 'ok')
            && ! $slots->contains('status', 'integrazione_richiesta');
    }

    /** @return array<string,string> id prodotto => nome */
    public static function productLabels(): array
    {
        return app(FlowRepository::class)->node('richiesta', 'prodotto')['options'];
    }

    /**
     * Rende leggibili risposte o dati personali: etichetta della domanda => titolo dell'opzione scelta.
     *
     * @return array<string,string>
     */
    public static function describe(?array $values, string $flow): array
    {
        $readable = [];
        $def = app(FlowRepository::class)->flow($flow) ?? [];
        foreach ($values ?? [] as $key => $value) {
            if (str_starts_with((string) $key, '_') || ! is_scalar($value)) {
                continue;
            }
            $node = $def['nodes'][$key] ?? [];
            $label = $def['labels'][$key] ?? $node['label'] ?? $key;
            $readable[$label] = (string) ($node['options'][$value] ?? $value);
        }

        return $readable;
    }
}
