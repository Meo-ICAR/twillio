<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'crm_sent_at' => 'datetime', 'analysis' => 'encrypted:array', 'pending_checks' => 'array', 'ai_cost' => 'decimal:6'];
    }

    /** Costo in dollari come testo («$ 0,0123»), oppure null se non c'è stata una lettura con l'AI. */
    public static function formatCost(null|float|string $cost): ?string
    {
        return $cost === null ? null : '$ '.number_format((float) $cost, 4, ',', '.');
    }

    public function praticaDocument(): BelongsTo
    {
        return $this->belongsTo(PraticaDocument::class);
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
