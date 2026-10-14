<?php

namespace App\Services\Flows;

use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Support\Facades\Schema;

/**
 * L'albero delle conversazioni, nella stessa forma di config/finanziamento.php.
 * Si legge dalle tabelle; se non c'è ancora nessun percorso nel database si usa la configurazione.
 * Il risultato resta in memoria per la durata della richiesta.
 *
 * Ogni percorso può avere una copia di prova (is_test). In modalità prova (setTest) si usa la copia
 * quando c'è, altrimenti la produzione. La modalità la imposta il motore per chi è associato a un utente.
 */
class FlowRepository
{
    /** @var array<int,array<int,array<string,array<string,mixed>>>> memoria per company e variante: 0 = produzione, 1 = prova */
    private array $memo = [];

    private bool $test = false;

    private ?int $company = null;

    /** @var array{0: ?int}|null la company più vecchia, letta una volta sola */
    private ?array $oldest = null;

    public function setCompany(?int $companyId): void
    {
        $this->company = $companyId;
    }

    /** Esegue $read leggendo i percorsi di una company (per codice che non gira nel dialogo, come mail e pannello). */
    public function withCompany(?int $companyId, \Closure $read): mixed
    {
        $previous = $this->company;
        $this->company = $companyId;

        try {
            return $read($this);
        } finally {
            $this->company = $previous;
        }
    }

    /** La company di cui si leggono i percorsi: quella impostata, altrimenti la più vecchia (installazione a una sola azienda). */
    private function effectiveCompany(): ?int
    {
        if ($this->company !== null) {
            return $this->company;
        }
        $this->oldest ??= [Schema::hasTable('companies') ? Company::current()?->id : null];

        return $this->oldest[0];
    }

    public function setTest(bool $test): void
    {
        $this->test = $test;
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    /** Tutti i percorsi nella modalità corrente; in prova, le copie sostituiscono i percorsi di produzione. */
    public function all(): array
    {
        $production = $this->variant(false);

        return $this->test ? array_replace($production, $this->variant(true)) : $production;
    }

    /** I percorsi di produzione, qualunque sia la modalità corrente. */
    public function production(): array
    {
        return $this->variant(false);
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

    /** Codici dei percorsi che hanno una copia di prova attiva. @return list<string> */
    public function testFlowCodes(): array
    {
        return array_keys($this->variant(true));
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->oldest = null;
    }

    /** @return array<string,array<string,mixed>> */
    private function variant(bool $test): array
    {
        $company = $this->effectiveCompany() ?? 0;

        return $this->memo[$company][(int) $test] ??= $this->load($test);
    }

    /** @return array<string,array<string,mixed>> */
    private function load(bool $test): array
    {
        // Prima della migrazione, o se non è stato importato nulla, vale la configurazione.
        if (! Schema::hasTable('flows') || ! Schema::hasColumn('flows', 'is_test')) {
            return $test ? [] : config('finanziamento.flows');
        }
        if (! $test && Flow::where('is_test', false)->doesntExist()) {
            return config('finanziamento.flows');
        }

        $result = [];
        $company = $this->effectiveCompany();
        $flows = Flow::where('is_test', $test)->where('is_active', true)
            // I percorsi senza company sono comuni a tutte; quello della company, se c'è, prevale (viene dopo).
            ->when($company, fn ($q) => $q->where(fn ($q) => $q->where('company_id', $company)->orWhereNull('company_id')))
            ->with(['nodes.options', 'nodes.jumps'])->orderByRaw('company_id is not null')->orderBy('id')->get();

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
        if ($node->jumps->isNotEmpty()) {
            // Un solo salto predefinito equivale a un salto fisso.
            $def['next'] = $node->jumps->count() === 1 && $node->jumps->first()->when_value === '*'
                ? $node->jumps->first()->go_to
                : $node->jumps->mapWithKeys(fn ($j) => [$j->when_value => $j->go_to])->all();
        }
        if ($node->jump_by !== 'answer') {
            $def['next_by'] = $node->jump_by;
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
        if ($node->can_modify) {
            $def['can_modify'] = true;
        }

        return $def;
    }
}
