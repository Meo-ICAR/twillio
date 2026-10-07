<?php

namespace Tests\Feature;

use App\Filament\Resources\Flows\Pages\EditFlow;
use App\Filament\Resources\Flows\RelationManagers\NodesRelationManager;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\User;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlowAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->seed(FlowSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function flow(string $code = 'richiesta'): Flow
    {
        return Flow::where('code', $code)->firstOrFail();
    }

    private function node(string $code, string $flow = 'richiesta'): FlowNode
    {
        return FlowNode::where('flow_id', $this->flow($flow)->id)->where('code', $code)->firstOrFail();
    }

    private function manager(string $flow = 'richiesta')
    {
        return Livewire::test(NodesRelationManager::class, ['ownerRecord' => $this->flow($flow), 'pageClass' => EditFlow::class]);
    }

    private function formOptions(FlowNode $node): array
    {
        return $node->options->map(fn ($o) => ['code' => $o->code, 'title' => $o->title])->all();
    }

    public function test_l_elenco_dei_percorsi_e_la_pagina_di_modifica_si_aprono(): void
    {
        $this->get('/admin/flows')->assertOk()->assertSee('Richiedi Finanziamento')->assertSee('Perfeziona Finanziamento');
        $this->get('/admin/flows/'.$this->flow()->id.'/edit')->assertOk()->assertSee('Intestazione');
        $this->get('/admin/flows/create')->assertNotFound();
    }

    public function test_si_modifica_l_intestazione_del_percorso_e_il_bot_la_usa(): void
    {
        $flow = $this->flow();

        Livewire::test(EditFlow::class, ['record' => $flow->getRouteKey()])
            ->fillForm(['header' => "📋 *Richiesta*\nSolo scelte.", 'name' => 'Richiedi Finanziamento', 'is_active' => true])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame("📋 *Richiesta*\nSolo scelte.", app(FlowRepository::class)->flow('richiesta')['header']);

        Livewire::test(EditFlow::class, ['record' => $flow->getRouteKey()])
            ->fillForm(['header' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertArrayNotHasKey('header', app(FlowRepository::class)->flow('richiesta'));
    }

    public function test_un_intestazione_troppo_lunga_non_si_salva(): void
    {
        Livewire::test(EditFlow::class, ['record' => $this->flow()->getRouteKey()])
            ->fillForm(['header' => str_repeat('x', 1001)])->call('save')->assertHasFormErrors(['header']);

        $this->assertNull($this->flow()->header);
    }

    public function test_si_modificano_testo_etichetta_titoli_e_flag_saltabile(): void
    {
        $node = $this->node('importo');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => 'Che cifra ti serve?', 'label' => 'Cifra', 'skippable' => true,
            'options' => array_replace($this->formOptions($node), [0 => ['code' => 'imp_5k', 'title' => 'Fino a 5 mila']]),
        ])->assertHasNoTableActionErrors();

        $def = app(FlowRepository::class)->node('richiesta', 'importo');
        $this->assertSame('Che cifra ti serve?', $def['prompt']);
        $this->assertSame('Cifra', $def['label']);
        $this->assertTrue($def['skippable']);
        $this->assertSame('Fino a 5 mila', $def['options']['imp_5k']);
        $this->assertSame(['imp_5k', 'imp_10k', 'imp_20k', 'imp_35k', 'imp_oltre'], array_keys($def['options']), 'ordine invariato');
    }

    public function test_si_aggiunge_e_si_toglie_un_opzione_dove_il_salto_e_fisso(): void
    {
        $node = $this->node('importo');
        $options = $this->formOptions($node);

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'options' => [...$options, ['code' => 'imp_mega', 'title' => 'Oltre 100.000 €']]]);
        $this->assertArrayHasKey('imp_mega', app(FlowRepository::class)->node('richiesta', 'importo')['options']);

        $this->manager()->callTableAction('edit', $node->fresh(), data: ['prompt' => $node->prompt, 'options' => array_slice($options, 0, 3)]);
        $this->assertSame(['imp_5k', 'imp_10k', 'imp_20k'], array_keys(app(FlowRepository::class)->node('richiesta', 'importo')['options']));
    }

    public function test_un_titolo_oltre_24_caratteri_viene_rifiutato_e_non_cambia_nulla(): void
    {
        $node = $this->node('importo');
        $before = app(FlowRepository::class)->node('richiesta', 'importo');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => 'Cambiata?', 'options' => array_replace($this->formOptions($node), [0 => ['code' => 'imp_5k', 'title' => str_repeat('x', 25)]]),
        ]);

        $this->assertSame($before, app(FlowRepository::class)->node('richiesta', 'importo'));
    }

    public function test_non_si_rende_saltabile_una_domanda_senza_uscita_predefinita(): void
    {
        $node = $this->node('impegni');

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'skippable' => true, 'options' => $this->formOptions($node)]);

        $this->assertFalse($node->fresh()->skippable);
    }

    public function test_non_si_aggiunge_un_opzione_dove_mancherebbe_il_salto(): void
    {
        $node = $this->node('impegni');

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'options' => [...$this->formOptions($node), ['code' => 'forse', 'title' => 'Forse']]]);

        $this->assertSame(['si', 'no'], array_keys(app(FlowRepository::class)->node('richiesta', 'impegni')['options']));
    }

    public function test_le_domande_di_testo_non_hanno_opzioni_e_si_modificano_comunque(): void
    {
        $node = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => 'Dove abita il cliente?', 'skippable' => true]);

        $def = app(FlowRepository::class)->node('perfezionamento', 'residenza');
        $this->assertSame('Dove abita il cliente?', $def['prompt']);
        $this->assertTrue($def['skippable']);
        $this->assertArrayNotHasKey('options', $def);
    }

    public function test_la_tabella_mostra_le_domande_in_ordine(): void
    {
        // La tabella è paginata: si controllano le prime domande, nell'ordine del dialogo.
        $first = $this->flow()->nodes->take(5);

        $this->manager()->assertCanSeeTableRecords($first, inOrder: true)->assertSee('prodotto')->assertTableColumnExists('skippable');
    }

    public function test_si_aggancia_un_controllo_a_una_domanda_dal_pannello(): void
    {
        $node = $this->node('residenza', 'perfezionamento');
        $this->assertNull($node->checks);

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'checks' => ['iban']])->assertHasNoTableActionErrors();

        $this->assertSame(['iban'], $node->fresh()->checks);
        $this->assertSame(['iban'], app(FlowRepository::class)->node('perfezionamento', 'residenza')['checks']);
    }

    public function test_modificare_una_domanda_conserva_i_parametri_dei_controlli_gia_agganciati(): void
    {
        $node = $this->node('codice_fiscale', 'perfezionamento');
        $before = $node->checks;
        $this->assertSame('maggiorenne', $before[1]['name']);

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => 'Codice fiscale del cliente?', 'checks' => ['codice_fiscale', 'maggiorenne']]);

        $this->assertSame($before, $node->fresh()->checks, 'età minima e campo restano com\'erano');
        $this->assertSame('Codice fiscale del cliente?', $node->fresh()->prompt);
    }

    public function test_si_toglie_un_controllo_e_si_possono_togliere_tutti(): void
    {
        $node = $this->node('codice_fiscale', 'perfezionamento');

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'checks' => ['codice_fiscale']]);
        $this->assertSame(['codice_fiscale'], $node->fresh()->checks);

        $this->manager('perfezionamento')->callTableAction('edit', $node->fresh(), data: ['prompt' => $node->prompt, 'checks' => []]);
        $this->assertNull($node->fresh()->checks);
        $this->assertArrayNotHasKey('checks', app(FlowRepository::class)->node('perfezionamento', 'codice_fiscale'));
    }

    public function test_un_controllo_che_non_esiste_non_si_salva(): void
    {
        $node = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'checks' => ['non_esiste']]);

        $this->assertNull($node->fresh()->checks);
    }

    public function test_la_scheda_della_domanda_elenca_i_controlli_disponibili_con_la_loro_descrizione(): void
    {
        $node = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->mountTableAction('edit', $node)
            ->assertMountedActionModalSee(['Controlli sulla risposta', 'Codice fiscale', 'IBAN', 'Età minima', 'checksum']);
    }

    public function test_le_domande_con_file_non_hanno_i_controlli(): void
    {
        $node = $this->node('doc_identita', 'perfezionamento');

        $this->manager('perfezionamento')->mountTableAction('edit', $node)
            ->assertMountedActionModalSee('Testo della domanda')
            ->assertMountedActionModalDontSee('Controlli sulla risposta');
    }
}
