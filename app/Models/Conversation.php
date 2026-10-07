<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'history' => 'array',
            'is_test' => 'boolean',
        ];
    }

    /** Dopo quanti minuti una conversazione a cui non si è ancora risposto si considera abbandonata. */
    public const UNTOUCHED_MINUTES = 60;

    /** Conversazioni attive a cui non si è mai risposto e ferme da più di un'ora. */
    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where('status', 'attiva')->where('updated_at', '<', now()->subMinutes(self::UNTOUCHED_MINUTES))
            ->where(fn ($q) => $q->whereNull('history')->orWhere('history', '[]'));
    }

    /** Il produttore (agente) il cui telefono coincide con il numero WhatsApp della conversazione. */
    public function getFornitoreAttribute(): ?Fornitore
    {
        return Fornitore::findByWhatsApp($this->wa_number);
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
