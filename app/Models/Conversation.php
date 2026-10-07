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

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
