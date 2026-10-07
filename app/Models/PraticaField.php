<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un dato della pratica letto dall'AI da un documento: resta una proposta finché l'agente non lo conferma. */
class PraticaField extends Model
{
    public const STATUSES = ['proposto' => 'Proposto', 'confermato' => 'Confermato', 'rifiutato' => 'Rifiutato'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'encrypted', 'confirmed_at' => 'datetime'];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * Registra i dati letti da un documento. Un dato già proposto si aggiorna con l'ultimo documento letto;
     * uno già confermato o rifiutato dall'agente non si tocca.
     *
     * @param  array<string,string>  $values  chiave => valore
     */
    public static function propose(LoanRequest $loan, array $values, Attachment $source): void
    {
        $code = $source->praticaDocument?->code ?? $source->kind;

        foreach ($values as $key => $value) {
            $field = $loan->fields()->firstWhere('key', $key);

            if ($field && $field->status !== 'proposto') {
                continue;
            }

            $loan->fields()->updateOrCreate(['key' => $key], [
                'value' => $value, 'status' => 'proposto', 'source_code' => $code, 'attachment_id' => $source->id,
            ]);
        }
    }

    /** L'agente conferma il dato, se serve correggendolo. */
    public function confirm(?string $value = null): void
    {
        $this->update(['value' => $value ?? $this->value, 'status' => 'confermato', 'confirmed_at' => now()]);
    }

    /** L'agente non riconosce il dato: non si usa. */
    public function reject(): void
    {
        $this->update(['status' => 'rifiutato']);
    }

    /**
     * Dati noti sulla pratica con cui confrontare un documento: quelli già letti dagli altri documenti.
     * Si escludono quelli dello stesso documento, che un nuovo invio sta sostituendo.
     *
     * @return array<string,string>
     */
    public static function knownValues(LoanRequest $loan, ?string $exceptSource = null): array
    {
        return $loan->fields()->where('status', '!=', 'rifiutato')
            ->when($exceptSource, fn ($q) => $q->where(fn ($q) => $q->where('source_code', '!=', $exceptSource)->orWhere('status', 'confermato')))
            ->get()->pluck('value', 'key')->all();
    }

    /** @return array<string,string> */
    public static function confirmedValues(LoanRequest $loan): array
    {
        return $loan->fields()->where('status', 'confermato')->get()->pluck('value', 'key')->all();
    }
}
