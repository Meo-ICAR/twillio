<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowValidator;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FlowSeeder::class);
    }

    private function node(string $flow, string $code): FlowNode
    {
        return FlowNode::whereHas('flow', fn ($q) => $q->where('code', $flow))->where('code', $code)->firstOrFail();
    }

    private function titles(FlowNode $node): array
    {
        return $node->options->pluck('title', 'code')->all();
    }

    private function errors(FlowNode $node, ?string $prompt = null, ?array $options = null, ?bool $skippable = null): array
    {
        return app(FlowValidator::class)->nodeErrors($node, $prompt ?? $node->prompt, $options ?? $this->titles($node), $skippable ?? $node->skippable);
    }

    public function test_l_albero_importato_non_ha_errori(): void
    {
        foreach (Flow::all() as $flow) {
            $this->assertSame([], app(FlowValidator::class)->flowErrors($flow), $flow->code);
            foreach ($flow->nodes as $node) {
                $this->assertSame([], $this->errors($node), "{$flow->code}.{$node->code}");
            }
        }
    }

    public function test_il_testo_della_domanda_serve_ed_e_limitato(): void
    {
        $node = $this->node('richiesta', 'importo');

        $this->assertNotEmpty($this->errors($node, '   '));
        $this->assertNotEmpty($this->errors($node, str_repeat('x', 901)));
        $this->assertSame([], $this->errors($node, 'Nuovo testo?'));
    }

    public function test_i_titoli_delle_opzioni_hanno_i_limiti_di_whatsapp(): void
    {
        $node = $this->node('richiesta', 'importo');
        $options = $this->titles($node);

        $this->assertNotEmpty($this->errors($node, null, ['imp_5k' => str_repeat('x', 25)] + $options));
        $this->assertNotEmpty($this->errors($node, null, ['imp_5k' => '  '] + $options));
        $this->assertNotEmpty($this->errors($node, null, []));
        $this->assertNotEmpty($this->errors($node, null, array_combine(array_map(fn ($i) => "o$i", range(1, 11)), array_fill(0, 11, 'Titolo'))));
        $this->assertSame([], $this->errors($node, null, ['imp_5k' => str_repeat('x', 24)] + $options));
    }

    public function test_aggiungere_un_opzione_a_una_domanda_con_salti_per_risposta_richiede_il_salto(): void
    {
        $impegni = $this->node('richiesta', 'impegni'); // salti si/no, nessun '*'
        $withNew = $this->titles($impegni) + ['forse' => 'Forse'];

        $errors = $this->errors($impegni, null, $withNew);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('forse', implode(' ', $errors));

        $crif = $this->node('richiesta', 'crif'); // ha '*'
        $this->assertSame([], $this->errors($crif, null, $this->titles($crif) + ['forse' => 'Forse']));

        $importo = $this->node('richiesta', 'importo'); // salto fisso
        $this->assertSame([], $this->errors($importo, null, $this->titles($importo) + ['nuova' => 'Nuova']));
    }

    public function test_si_puo_saltare_solo_con_un_uscita_predefinita(): void
    {
        $this->assertSame([], $this->errors($this->node('richiesta', 'importo'), null, null, true), 'salto fisso');
        $this->assertSame([], $this->errors($this->node('richiesta', 'crif'), null, null, true), "uscita '*'");
        $this->assertNotEmpty($this->errors($this->node('richiesta', 'impegni'), null, null, true), 'solo si/no');
        $this->assertNotEmpty($this->errors($this->node('richiesta', 'riepilogo'), null, null, true), 'il riepilogo non si salta');
    }

    public function test_il_percorso_deve_avere_inizio_e_ripartenza_esistenti_e_salti_validi(): void
    {
        $flow = Flow::where('code', 'richiesta')->first();
        $flow->update(['start' => 'non_esiste']);
        $this->assertNotEmpty(app(FlowValidator::class)->flowErrors($flow->fresh()));

        $flow->update(['start' => 'prodotto', 'restart' => 'non_esiste']);
        $this->assertNotEmpty(app(FlowValidator::class)->flowErrors($flow->fresh()));

        $flow->update(['restart' => 'prodotto']);
        FlowNode::where('flow_id', $flow->id)->where('code', 'importo')->update(['next_to' => 'domanda_fantasma']);
        $errors = app(FlowValidator::class)->flowErrors($flow->fresh());
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('domanda_fantasma', implode(' ', $errors));
    }

    public function test_l_intestazione_ha_un_limite(): void
    {
        $flow = Flow::where('code', 'richiesta')->first();
        $flow->update(['header' => str_repeat('x', 1001)]);

        $this->assertNotEmpty(app(FlowValidator::class)->flowErrors($flow->fresh()));
    }
}
