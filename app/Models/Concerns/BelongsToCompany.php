<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Predisposizione al multitenant: il record appartiene a una company.
 * Il tenant NON è attivo: nessun filtro automatico e nessun valore assegnato in creazione.
 */
trait BelongsToCompany
{
    /** Colonna con la company (le tabelle che hanno già un `company_id` con altro significato usano un altro nome). */
    public static function companyColumn(): string
    {
        return 'company_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, static::companyColumn());
    }
}
