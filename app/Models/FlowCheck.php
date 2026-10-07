<?php

namespace App\Models;

use App\Services\Checks\CheckRegistry;
use App\Services\Checks\NodeCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Un controllo disponibile per le domande: il codice con cui si aggancia e la classe che lo esegue. */
class FlowCheck extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => app(CheckRegistry::class)->forget());
        static::deleted(fn () => app(CheckRegistry::class)->forget());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Il codice si ricava dal nome della classe: MaggiorenneCheck → maggiorenne, CodiceFiscaleCheck → codice_fiscale. */
    public static function codeFor(string $class): string
    {
        return Str::snake(Str::beforeLast(class_basename($class), 'Check'));
    }

    public function instance(): ?NodeCheck
    {
        return class_exists($this->class) && is_subclass_of($this->class, NodeCheck::class) ? app($this->class) : null;
    }

    public function label(): string
    {
        return $this->instance()?->label() ?? $this->code;
    }

    public function description(): string
    {
        return $this->instance()?->description() ?? 'La classe di questo controllo non esiste più.';
    }

    /**
     * Domande che lo usano, come «percorso.domanda».
     *
     * @return Collection<int,string>
     */
    public function usedBy(): Collection
    {
        return FlowNode::whereNotNull('checks')->with('flow')->get()
            ->filter(fn (FlowNode $node) => collect($node->checks)->contains(fn ($e) => (is_array($e) ? ($e['name'] ?? null) : $e) === $this->code))
            ->map(fn (FlowNode $node) => "{$node->flow->code}.{$node->code}")
            ->values();
    }
}
