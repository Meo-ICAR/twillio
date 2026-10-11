<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo_rapporto del servizio; `contracts` = contratti con cui è ammesso (null = tutti). */
class QuoteEmploymentType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['contracts' => 'array'];
    }
}
