<?php

namespace App\Models;

use App\Services\Flows\FlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una domanda di un percorso. */
class FlowNode extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => app(FlowRepository::class)->forget());
        static::deleted(fn () => app(FlowRepository::class)->forget());
    }

    protected function casts(): array
    {
        return ['skippable' => 'boolean', 'save' => 'boolean', 'next_map' => 'array', 'params' => 'array', 'checks' => 'array'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FlowNodeOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
