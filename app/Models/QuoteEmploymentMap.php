<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dalle risposte del produttore (situazione lavorativa, ente, dimensione azienda) al Tipo_rapporto; le colonne nulle valgono «qualunque». */
class QuoteEmploymentMap extends Model
{
    protected $table = 'quote_employment_map';

    protected $guarded = [];

    /** La riga più specifica per le risposte del produttore (lavoro, ente pensione, dimensione azienda), o null. */
    public static function resolve(array $answers): ?self
    {
        return static::where('lavoro', $answers['lavoro'] ?? '')
            ->where(fn ($q) => $q->whereNull('ente_pensione')->orWhere('ente_pensione', $answers['ente_pensione'] ?? ''))
            ->where(fn ($q) => $q->whereNull('dimensione_azienda')->orWhere('dimensione_azienda', $answers['dimensione_azienda'] ?? ''))
            ->orderByDesc('priority')->first();
    }
}
