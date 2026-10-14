<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Prodotto del catalogo, per settore (FINANCE, CALL CENTER, HOTEL). Si associa alle company con `company_products`. */
class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'params' => 'array'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('products.is_active', true);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_products')->withTimestamps();
    }
}
