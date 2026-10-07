<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanRequest extends Model
{
    protected $guarded = [];

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
}
