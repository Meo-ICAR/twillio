<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Venditore di energia elettrica e/o gas dall'elenco ARERA (dati inseriti dagli operatori stessi). */
class EnergySupplier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sells_electricity' => 'boolean', 'sells_gas' => 'boolean'];
    }
}
