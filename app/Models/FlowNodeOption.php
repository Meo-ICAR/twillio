<?php

namespace App\Models;

use App\Services\Flows\FlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un'opzione di risposta di una domanda a scelta. */
class FlowNodeOption extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => app(FlowRepository::class)->forget());
        static::deleted(fn () => app(FlowRepository::class)->forget());
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'flow_node_id');
    }
}
