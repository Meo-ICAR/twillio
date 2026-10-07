<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Azienda cliente: è il Titolare del trattamento indicato nell'informativa privacy. */
class Company extends Model
{
    protected $guarded = [];

    /** L'installazione serve una sola azienda: vale la prima. */
    public static function current(): ?self
    {
        return static::oldest('id')->first();
    }
}
