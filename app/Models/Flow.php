<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Flows\FlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un percorso di conversazione con le sue domande. */
class Flow extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => app(FlowRepository::class)->forget());
        static::deleted(fn () => app(FlowRepository::class)->forget());
    }

    protected function casts(): array
    {
        return ['labels' => 'array', 'is_active' => 'boolean', 'is_test' => 'boolean'];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class)->orderBy('sort_order')->orderBy('id');
    }
}
