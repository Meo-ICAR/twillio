<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Azienda cliente: è il Titolare del trattamento indicato nell'informativa privacy. */
class Company extends Model
{
    protected $guarded = [];

    /** Esiste un CRM per la fase di preventivazione (altrimenti si manda una email all'istruttoria). */
    public function hasQuoteCrm(): bool
    {
        return filled($this->url_preventivatore);
    }

    /** Esiste un CRM per l'istruttoria (altrimenti si manda una email con dati e allegati). */
    public function hasSubmissionCrm(): bool
    {
        return filled($this->url_istruttoria);
    }

    /** L'installazione serve una sola azienda: vale la prima. */
    public static function current(): ?self
    {
        return static::oldest('id')->first();
    }
}
