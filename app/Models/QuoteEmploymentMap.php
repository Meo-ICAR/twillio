<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dalle risposte del produttore (situazione lavorativa, ente, dimensione azienda) al Tipo_rapporto; le colonne nulle valgono «qualunque». */
class QuoteEmploymentMap extends Model
{
    protected $table = 'quote_employment_map';

    protected $guarded = [];
}
