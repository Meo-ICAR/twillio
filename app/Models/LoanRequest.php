<?php

namespace App\Models;

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

    protected $guarded = [];

    protected static function booted(): void
    {
        // I file dei clienti vivono solo finché esiste la pratica.
        static::deleting(fn (self $loan) => Storage::disk('local')->deleteDirectory("pratiche/{$loan->code}"));
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'personal' => 'encrypted:array',
            'privacy_received_at' => 'datetime',
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
        return config('finanziamento.flows.richiesta.nodes.prodotto.options');
    }

    /**
     * Rende leggibili risposte o dati personali: etichetta della domanda => titolo dell'opzione scelta.
     *
     * @return array<string,string>
     */
    public static function describe(?array $values, string $flow): array
    {
        $readable = [];
        foreach ($values ?? [] as $key => $value) {
            if (str_starts_with((string) $key, '_') || ! is_scalar($value)) {
                continue;
            }
            $node = config("finanziamento.flows.{$flow}.nodes.{$key}") ?? [];
            $label = config("finanziamento.flows.{$flow}.labels.{$key}") ?? $node['label'] ?? $key;
            $readable[$label] = (string) ($node['options'][$value] ?? $value);
        }

        return $readable;
    }
}
