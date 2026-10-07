<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un documento atteso (o richiesto) per una pratica, con il suo stato e le annotazioni di AI e operatore. */
class PraticaDocument extends Model
{
    public const STATUSES = [
        'da_ricevere' => 'Da ricevere',
        'ricevuto' => 'Ricevuto',
        'ok' => 'OK',
        'rejected' => 'Rifiutato',
        'integrazione_richiesta' => 'Integrazione richiesta',
    ];

    public const AUTHORS = ['ai', 'operatore'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'annotations' => 'encrypted:array',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FinanziamentoDocument::class, 'finanziamento_document_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Crea i documenti obbligatori e facoltativi previsti per il prodotto della pratica.
     * Gli integrativi non si creano: li richiede l'istruttore quando serve un approfondimento. Ripetibile.
     *
     * @return Collection<int,PraticaDocument>
     */
    public static function populate(LoanRequest $loan): Collection
    {
        FinanziamentoDocument::active()
            ->where('product', $loan->product)
            ->whereIn('requirement', ['obbligatorio', 'facoltativo'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->each(fn (FinanziamentoDocument $doc) => static::firstOrCreate(
                ['loan_request_id' => $loan->id, 'code' => $doc->code],
                ['finanziamento_document_id' => $doc->id, 'name' => $doc->name, 'requirement' => $doc->requirement, 'sort_order' => $doc->sort_order],
            ));

        return $loan->praticaDocuments()->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Aggiunge un'annotazione dell'AI o di un operatore. */
    public function addAnnotation(string $by, string $text, ?int $userId = null): void
    {
        if (! in_array($by, self::AUTHORS, true)) {
            throw new \InvalidArgumentException("Autore delle annotazioni non valido: {$by}");
        }

        $notes = $this->annotations ?? [];
        $notes[] = ['by' => $by, 'user_id' => $userId, 'text' => $text, 'at' => now()->toIso8601String()];
        $this->update(['annotations' => $notes]);
    }

    /** L'operatore dà il documento per buono. */
    public function approve(int $userId): void
    {
        $this->update(['status' => 'ok', 'reviewed_at' => now()]);
        $this->addAnnotation('operatore', 'Approvato dall\'operatore.', $userId);

        if ($this->code === 'informativa') {
            $this->loanRequest->verifyPrivacy();
        }
    }

    /** L'operatore rifiuta il documento: la nota dice all'agente cosa non va. */
    public function reject(string $note, int $userId): void
    {
        $this->review('rejected', $note, $userId);
    }

    /** L'operatore chiede un approfondimento su questo documento. */
    public function requestIntegration(string $note, int $userId): void
    {
        $this->review('integrazione_richiesta', $note, $userId);
    }

    private function review(string $status, string $note, int $userId): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('Serve una nota per spiegare cosa manca o cosa non va.');
        }

        $this->update(['status' => $status, 'reviewed_at' => now()]);
        $this->addAnnotation('operatore', $note, $userId);

        if ($this->code === 'informativa') {
            $this->loanRequest->revokePrivacy();
        }
    }

    public function lastAnnotation(): ?string
    {
        $notes = $this->annotations ?? [];

        return $notes ? end($notes)['text'] : null;
    }
}
