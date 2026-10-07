<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'history' => 'array',
            'is_test' => 'boolean',
        ];
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
