<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Esito di una simulazione (scenario `best` o `worst`) per una richiesta: dati inviati, risposta grezza, importi erogati. */
class QuoteSimulation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array'];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
