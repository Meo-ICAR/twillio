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
        return ['received_at' => 'datetime', 'analysis' => 'encrypted:array', 'pending_checks' => 'array'];
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
