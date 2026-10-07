<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Flows\FlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un salto: quando la risposta (o il dato) vale `when_value`, il dialogo prosegue dalla domanda `go_to`. */
class FlowNodeJump extends Model
{
    use BelongsToCompany;

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
