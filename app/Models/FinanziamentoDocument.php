<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Documento previsto per un tipo di finanziamento (catalogo modificabile dal pannello). */
class FinanziamentoDocument extends Model
{
    public const REQUIREMENTS = ['obbligatorio' => 'Obbligatorio', 'facoltativo' => 'Facoltativo', 'integrativo' => 'Integrativo'];

    public const AI_KINDS = ['identita' => 'Documento d\'identità', 'codice_fiscale' => 'Codice fiscale', 'reddito' => 'Documento di reddito'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
