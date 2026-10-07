<?php

namespace App\Services\Flows;

use App\Models\Flow;
use Illuminate\Support\Facades\DB;

/** Crea la copia di prova di un percorso e la pubblica in produzione. */
class FlowCloner
{
    public function __construct(private FlowValidator $validator, private FlowRepository $repository) {}

    /**
     * Copia un percorso di produzione in una copia di prova modificabile.
     *
     * @param  bool  $replace  se vero rifà la copia partendo dalla produzione, perdendo le modifiche di prova
     *
     * @throws \DomainException se la copia c'è già e non si chiede di rifarla, o se il percorso non è di produzione
     */
    public function createTestCopy(Flow $production, bool $replace = false): Flow
    {
        if ($production->is_test) {
            throw new \DomainException('La copia di prova si crea partendo da un percorso di produzione.');
        }

        $existing = Flow::where('code', $production->code)->where('is_test', true)->first();
        if ($existing && ! $replace) {
            throw new \DomainException('Questo percorso ha già una copia di prova.');
        }

        return DB::transaction(function () use ($production, $existing) {
            $existing?->delete();

            $copy = Flow::create([
                'code' => $production->code,
                'name' => $production->name.' (prova)',
                'header' => $production->header,
                'start' => $production->start,
                'restart' => $production->restart,
                'labels' => $production->labels,
                'is_active' => true,
                'is_test' => true,
            ]);
            $this->copyNodes($production, $copy);

            return $copy;
        });
    }

    /**
     * Porta in produzione il contenuto della copia di prova. Si rifiuta se la copia ha errori
     * che bloccherebbero gli agenti; la copia di prova resta com'è.
     *
     * @throws \DomainException
     */
    public function publish(Flow $test): Flow
    {
        if (! $test->is_test) {
            throw new \DomainException('Si pubblica solo una copia di prova.');
        }

        $errors = $this->validator->flowErrors($test);
        if ($errors) {
            throw new \DomainException('La copia di prova ha errori e non si può pubblicare: '.implode(' ', $errors));
        }

        $production = Flow::where('code', $test->code)->where('is_test', false)->firstOrFail();

        DB::transaction(function () use ($test, $production) {
            $production->update(['header' => $test->header, 'start' => $test->start, 'restart' => $test->restart, 'labels' => $test->labels]);
            $production->nodes()->get()->each->delete();
            $this->copyNodes($test, $production);
        });
        $this->repository->forget();

        return $production->fresh();
    }

    private function copyNodes(Flow $from, Flow $to): void
    {
        foreach ($from->nodes()->with(['options', 'jumps'])->get() as $node) {
            $copy = $to->nodes()->create($node->only([
                'code', 'type', 'label', 'prompt', 'sort_order', 'skippable', 'save', 'jump_by', 'params', 'checks',
            ]));

            foreach ($node->options as $option) {
                $copy->options()->create($option->only(['code', 'title', 'sort_order']));
            }
            foreach ($node->jumps as $jump) {
                $copy->jumps()->create($jump->only(['when_value', 'go_to', 'sort_order']));
            }
        }
    }
}
