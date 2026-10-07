<?php

namespace App\Services\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Support\Facades\Schema;

/**
 * L'albero delle conversazioni, nella stessa forma di config/finanziamento.php.
 * Si legge dalle tabelle; se non c'è ancora nessun percorso nel database si usa la configurazione.
 * Il risultato resta in memoria per la durata della richiesta.
 */
class FlowRepository
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $flows = null;

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->flows ??= $this->load();
    }

    /** @return array<string,mixed>|null */
    public function flow(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function node(string $flow, string $node): ?array
    {
        return $this->flow($flow)['nodes'][$node] ?? null;
    }

    public function forget(): void
    {
        $this->flows = null;
    }

    /** @return array<string,array<string,mixed>> */
    private function load(): array
    {
        // Prima della migrazione, o se non è stato importato nulla, vale la configurazione.
        if (! Schema::hasTable('flows') || Flow::query()->doesntExist()) {
            return config('finanziamento.flows');
        }

        $result = [];
        $flows = Flow::query()->where('is_active', true)->with('nodes.options')->orderBy('id')->get();

        foreach ($flows as $flow) {
            $def = ['start' => $flow->start, 'restart' => $flow->restart];
            if (filled($flow->labels)) {
                $def['labels'] = $flow->labels;
            }
            if (filled(trim((string) $flow->header))) {
                $def['header'] = $flow->header;
            }

            $def['nodes'] = [];
            foreach ($flow->nodes as $node) {
                $def['nodes'][$node->code] = $this->nodeDefinition($node);
            }

            $result[$flow->code] = $def;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function nodeDefinition(FlowNode $node): array
    {
        $def = ($node->params ?? []) + ['type' => $node->type, 'prompt' => $node->prompt];

        if ($node->label !== null) {
            $def['label'] = $node->label;
        }
        if ($node->options->isNotEmpty()) {
            $def['options'] = $node->options->mapWithKeys(fn ($o) => [$o->code => $o->title])->all();
        }
        if ($node->next_map !== null) {
            $def['next'] = $node->next_map;
        } elseif ($node->next_to !== null) {
            $def['next'] = $node->next_to;
        }
        if (filled($node->checks)) {
            $def['checks'] = $node->checks;
        }
        if (! $node->save) {
            $def['save'] = false;
        }
        if ($node->skippable) {
            $def['skippable'] = true;
        }

        return $def;
    }
}
