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
        return app(FlowValidator::class)->nodeErrors($node, $prompt ?? $node->prompt, $options ?? $this->titles($node), $skippable ?? $node->skippable, $node->checks ?? []);
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
        FlowNode::where('flow_id', $flow->id)->where('code', 'importo')->first()->jumps()->update(['go_to' => 'domanda_fantasma']);
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

    public function test_i_controlli_agganciati_devono_esistere_e_stare_su_domande_di_testo_o_scelta(): void
    {
        $validator = app(FlowValidator::class);
        $text = $this->node('perfezionamento', 'residenza');
        $file = $this->node('perfezionamento', 'doc_identita');
        $choice = $this->node('richiesta', 'importo');

        $this->assertSame([], $validator->nodeErrors($text, $text->prompt, [], false, ['iban', ['name' => 'maggiorenne', 'anni' => 18]]));
        $this->assertSame([], $validator->nodeErrors($choice, $choice->prompt, $this->titles($choice), false, ['iban']));

        $unknown = $validator->nodeErrors($text, $text->prompt, [], false, ['non_esiste']);
        $this->assertNotEmpty($unknown);
        $this->assertStringContainsString('non_esiste', implode(' ', $unknown));

        $this->assertNotEmpty($validator->nodeErrors($file, $file->prompt, [], false, ['iban']), 'su un file non ha senso');
        $this->assertNotEmpty($validator->nodeErrors($text, $text->prompt, [], false, [['anni' => 18]]), 'manca il nome');
    }

    public function test_l_albero_importato_ha_controlli_validi_in_ogni_domanda(): void
    {
        foreach (FlowNode::whereNotNull('checks')->get() as $node) {
            $this->assertSame([], $this->errors($node), "{$node->flow->code}.{$node->code}");
        }
        $this->assertGreaterThanOrEqual(2, FlowNode::whereNotNull('checks')->count());
    }

    private function jumpErrors(FlowNode $node, array $jumps, ?string $jumpBy = null, bool $skippable = false): array
    {
        return app(FlowValidator::class)->nodeErrors($node, $node->prompt, $this->titles($node), $skippable, $node->checks ?? [], $jumps, $jumpBy);
    }

    public function test_i_salti_devono_portare_a_domande_che_esistono(): void
    {
        $importo = $this->node('richiesta', 'importo');

        $this->assertSame([], $this->jumpErrors($importo, [['when' => '*', 'go_to' => 'riepilogo']]));

        $errors = $this->jumpErrors($importo, [['when' => '*', 'go_to' => 'domanda_fantasma']]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('domanda_fantasma', implode(' ', $errors));
    }

    public function test_una_domanda_deve_avere_almeno_un_salto_tranne_il_riepilogo(): void
    {
        $this->assertNotEmpty($this->jumpErrors($this->node('richiesta', 'importo'), []));
        $this->assertSame([], $this->jumpErrors($this->node('richiesta', 'riepilogo'), []));
    }

    public function test_non_si_ripete_la_stessa_condizione_due_volte(): void
    {
        $errors = $this->jumpErrors($this->node('richiesta', 'crif'), [['when' => 'no', 'go_to' => 'riepilogo'], ['when' => 'no', 'go_to' => 'bene'], ['when' => '*', 'go_to' => 'riepilogo']], 'answer');

        $this->assertStringContainsString('no', implode(' ', $errors));
        $this->assertNotEmpty($errors);
    }

    public function test_con_i_salti_per_risposta_ogni_opzione_ha_un_salto_o_c_e_quello_predefinito(): void
    {
        $impegni = $this->node('richiesta', 'impegni'); // si / no

        $this->assertSame([], $this->jumpErrors($impegni, [['when' => 'si', 'go_to' => 'rata'], ['when' => 'no', 'go_to' => 'crif']]));
        $this->assertNotEmpty($this->jumpErrors($impegni, [['when' => 'si', 'go_to' => 'rata']]), 'manca il no');
        $this->assertSame([], $this->jumpErrors($impegni, [['when' => 'si', 'go_to' => 'rata'], ['when' => '*', 'go_to' => 'crif']]));
    }

    public function test_il_dato_da_cui_dipendono_i_salti_deve_essere_una_domanda_del_percorso(): void
    {
        $durata = $this->node('richiesta', 'durata');
        $jumps = [['when' => '*', 'go_to' => 'riepilogo']];

        $this->assertSame([], $this->jumpErrors($durata, $jumps, 'prodotto'));
        $this->assertSame([], $this->jumpErrors($durata, $jumps, 'answer'));
        $errors = $this->jumpErrors($durata, $jumps, 'dato_che_non_esiste');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('dato_che_non_esiste', implode(' ', $errors));
    }

    public function test_per_saltare_serve_il_salto_predefinito_tra_quelli_proposti(): void
    {
        $impegni = $this->node('richiesta', 'impegni');
        $both = [['when' => 'si', 'go_to' => 'rata'], ['when' => 'no', 'go_to' => 'crif']];

        $this->assertNotEmpty($this->jumpErrors($impegni, $both, null, true));
        $this->assertSame([], $this->jumpErrors($impegni, [...$both, ['when' => '*', 'go_to' => 'crif']], null, true));
    }
}
