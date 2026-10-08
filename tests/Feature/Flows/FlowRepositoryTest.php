<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repo(): FlowRepository
    {
        return app(FlowRepository::class);
    }

    public function test_senza_dati_nel_database_si_usa_la_configurazione(): void
    {
        $this->assertSame(config('finanziamento.flows'), $this->repo()->all());
        $this->assertSame(config('finanziamento.flows.richiesta.start'), $this->repo()->flow('richiesta')['start']);
    }

    public function test_dopo_l_importazione_l_albero_dal_database_e_identico_a_quello_della_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $this->assertGreaterThan(0, FlowNode::count());

        $fromDb = $this->repo()->all();

        foreach (config('finanziamento.flows') as $code => $expected) {
            $this->assertEquals($expected, $fromDb[$code], "flusso $code");
            foreach ($expected['nodes'] as $name => $node) {
                // l'ordine delle opzioni è quello mostrato all'utente
                $this->assertSame(array_keys($node['options'] ?? []), array_keys($fromDb[$code]['nodes'][$name]['options'] ?? []), "$code.$name opzioni");
                $this->assertSame(array_keys($expected['nodes']), array_keys($fromDb[$code]['nodes']), "$code ordine dei nodi");
            }
        }
    }

    public function test_node_restituisce_la_definizione_o_null(): void
    {
        $this->seed(FlowSeeder::class);

        $this->assertSame('prodotto', $this->repo()->flow('richiesta')['start']);
        $this->assertSame('choice', $this->repo()->node('richiesta', 'prodotto')['type']);
        $this->assertNull($this->repo()->node('richiesta', 'inesistente'));
        $this->assertNull($this->repo()->node('inesistente', 'prodotto'));
    }

    public function test_le_modifiche_dal_database_hanno_effetto_subito(): void
    {
        $this->seed(FlowSeeder::class);
        $this->assertStringNotContainsString('MODIFICATA', $this->repo()->node('richiesta', 'importo')['prompt']);

        FlowNode::where('code', 'importo')->whereHas('flow', fn ($q) => $q->where('code', 'richiesta'))->first()->update(['prompt' => 'Domanda MODIFICATA?']);
        $this->assertSame('Domanda MODIFICATA?', $this->repo()->node('richiesta', 'importo')['prompt']);

        $node = FlowNode::where('code', 'importo')->first();
        $node->options()->where('code', 'imp_5k')->update(['title' => 'Titolo nuovo']);
        $node->options()->first()?->touch(); // l'osservatore invalida la cache
        $this->assertSame('Titolo nuovo', $this->repo()->node('richiesta', 'importo')['options']['imp_5k']);
    }

    public function test_un_flusso_disattivato_non_si_usa(): void
    {
        $this->seed(FlowSeeder::class);
        Flow::where('code', 'documenti')->update(['is_active' => false]);

        $this->assertNull($this->repo()->flow('documenti'));
        $this->assertNotNull($this->repo()->flow('richiesta'));
    }

    public function test_intestazione_e_salto_sono_salvati_e_riletti(): void
    {
        $this->seed(FlowSeeder::class);
        $flow = Flow::where('code', 'richiesta')->first();
        $flow->update(['header' => '📋 Richiesta anonima']);
        FlowNode::where('flow_id', $flow->id)->where('code', 'importo')->update(['skippable' => true]);
        $flow->touch();

        $this->assertSame('📋 Richiesta anonima', $this->repo()->flow('richiesta')['header']);
        $this->assertTrue($this->repo()->node('richiesta', 'importo')['skippable']);
        $this->assertArrayNotHasKey('skippable', $this->repo()->node('richiesta', 'durata'));
    }

    public function test_il_comando_di_importazione_non_sovrascrive_le_modifiche_se_non_richiesto(): void
    {
        $this->artisan('flows:import')->assertSuccessful();
        $count = FlowNode::count();
        FlowNode::where('code', 'importo')->update(['prompt' => 'Modificata a mano']);

        $this->artisan('flows:import')->assertSuccessful();
        $this->assertSame($count, FlowNode::count());
        $this->assertSame('Modificata a mano', $this->repo()->node('richiesta', 'importo')['prompt']);

        $this->artisan('flows:import', ['--force' => true])->assertSuccessful();
        $this->assertSame($count, FlowNode::count());
        $this->assertSame(config('finanziamento.flows.richiesta.nodes.importo.prompt'), $this->repo()->node('richiesta', 'importo')['prompt']);
    }
}
