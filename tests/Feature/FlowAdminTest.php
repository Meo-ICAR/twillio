<?php

namespace Tests\Feature;

use App\Filament\Resources\Flows\Pages\EditFlow;
use App\Filament\Resources\Flows\RelationManagers\NodesRelationManager;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\User;
use App\Services\Flows\FlowRepository;
use App\Services\Flows\FlowValidator;
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
            ->assertMountedActionModalSee(['Controlli sulla risposta', 'Codice fiscale', 'IBAN', 'Età minima']);
    }

    public function test_le_domande_con_file_hanno_i_controlli_sul_documento_non_quelli_sulla_risposta(): void
    {
        $node = $this->node('doc_identita', 'perfezionamento');

        $this->manager('perfezionamento')->mountTableAction('edit', $node)
            ->assertMountedActionModalSee(['Controlli sul documento', 'Tipo di documento', 'Informativa firmata'])
            ->assertMountedActionModalDontSee(['Controlli sulla risposta', 'Età minima']);
    }

    public function test_le_domande_di_testo_non_offrono_i_controlli_sui_documenti(): void
    {
        $node = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->mountTableAction('edit', $node)
            ->assertMountedActionModalSee('Età minima')
            ->assertMountedActionModalDontSee(['Tipo di documento', 'Controlli sul documento']);
    }

    public function test_si_aggancia_un_controllo_sul_documento_a_una_domanda_con_file(): void
    {
        $node = $this->node('doc_identita', 'perfezionamento');

        $this->manager('perfezionamento')->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'checks' => ['tipo_documento', 'estrai_dati']])->assertHasNoTableActionErrors();

        $this->assertSame(['tipo_documento', 'estrai_dati'], $node->fresh()->checks);
        $this->assertSame(['tipo_documento', 'estrai_dati'], app(FlowRepository::class)->node('perfezionamento', 'doc_identita')['checks']);
    }

    public function test_un_controllo_sulla_risposta_non_si_aggancia_a_un_file_e_viceversa(): void
    {
        $file = $this->node('doc_identita', 'perfezionamento');
        $text = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->callTableAction('edit', $file, data: ['prompt' => $file->prompt, 'checks' => ['iban']]);
        $this->manager('perfezionamento')->callTableAction('edit', $text, data: ['prompt' => $text->prompt, 'checks' => ['tipo_documento']]);

        $this->assertNull($file->fresh()->checks);
        $this->assertNull($text->fresh()->checks);
    }

    public function test_si_rende_modificabile_una_domanda_dal_pannello_solo_se_e_a_scelta_di_richiesta(): void
    {
        $node = $this->node('lavoro', 'richiesta');
        $this->assertFalse($node->can_modify);

        $this->manager('richiesta')->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'can_modify' => true, 'options' => $this->formOptions($node), 'jump_by' => $node->jump_by, 'jumps' => $this->formJumps($node)])->assertHasNoTableActionErrors();

        $this->assertTrue($node->fresh()->can_modify);
        $this->assertTrue(app(FlowRepository::class)->node('richiesta', 'lavoro')['can_modify']);
        $this->manager('richiesta')->assertTableColumnExists('can_modify');
    }

    public function test_il_pannello_non_offre_la_modifica_per_le_domande_di_perfezionamento(): void
    {
        $node = $this->node('residenza', 'perfezionamento');

        $this->manager('perfezionamento')->mountTableAction('edit', $node)->assertMountedActionModalDontSee('Modificabile nel preventivo');
        $this->manager('richiesta')->mountTableAction('edit', $this->node('importo', 'richiesta'))->assertMountedActionModalSee('Modificabile nel preventivo');
    }

    private function jumpsOf(string $code, string $flow = 'richiesta'): array
    {
        return $this->node($code, $flow)->jumps()->get()->map(fn ($j) => [$j->when_value, $j->go_to])->all();
    }

    private function formJumps(FlowNode $node): array
    {
        return $node->jumps()->get()->map(fn ($j) => ['when' => $j->when_value, 'go_to' => $j->go_to])->all();
    }

    public function test_si_cambiano_i_salti_di_una_domanda_dal_pannello(): void
    {
        $node = $this->node('importo');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'answer',
            'jumps' => [['when' => '*', 'go_to' => 'riepilogo']],
        ])->assertHasNoTableActionErrors();

        $this->assertSame([['*', 'riepilogo']], $this->jumpsOf('importo'));
        $this->assertSame('riepilogo', app(FlowRepository::class)->node('richiesta', 'importo')['next']);
    }

    public function test_si_aggiungono_salti_per_singola_risposta_e_si_riordinano(): void
    {
        $node = $this->node('importo');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'answer',
            'jumps' => [['when' => 'imp_oltre', 'go_to' => 'riepilogo'], ['when' => '*', 'go_to' => 'durata']],
        ]);

        $this->assertSame([['imp_oltre', 'riepilogo'], ['*', 'durata']], $this->jumpsOf('importo'));
        $this->assertSame(['imp_oltre' => 'riepilogo', '*' => 'durata'], app(FlowRepository::class)->node('richiesta', 'importo')['next']);
    }

    public function test_i_salti_dipendenti_dal_prodotto_si_modificano(): void
    {
        $node = $this->node('durata');
        $jumps = [...$this->formJumps($node), ['when' => '*', 'go_to' => 'riepilogo']]; // uscita per qualunque altro prodotto

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'prodotto', 'jumps' => $jumps])
            ->assertHasNoTableActionErrors();

        $def = app(FlowRepository::class)->node('richiesta', 'durata');
        $this->assertSame('riepilogo', $def['next']['*']);
        $this->assertSame('leasing_anticipo', $def['next']['leasing'], 'gli altri salti restano');
        $this->assertSame('prodotto', $def['next_by']);
    }

    public function test_modificare_solo_il_testo_non_tocca_i_salti(): void
    {
        $node = $this->node('lavoro');
        $before = $this->jumpsOf('lavoro');

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => 'Che lavoro fa il cliente?']);

        $this->assertSame($before, $this->jumpsOf('lavoro'));
        $this->assertSame('Che lavoro fa il cliente?', $node->fresh()->prompt);
    }

    public function test_un_salto_verso_una_domanda_inesistente_non_si_salva(): void
    {
        $node = $this->node('importo');
        $before = $this->jumpsOf('importo');

        $this->manager()->callTableAction('edit', $node, data: ['prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'answer', 'jumps' => [['when' => '*', 'go_to' => 'non_esiste']]]);

        $this->assertSame($before, $this->jumpsOf('importo'));
    }

    public function test_una_condizione_ripetuta_non_si_salva(): void
    {
        $node = $this->node('crif');
        $before = $this->jumpsOf('crif');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'prodotto',
            'jumps' => [['when' => 'quinto', 'go_to' => 'riepilogo'], ['when' => 'quinto', 'go_to' => 'bene'], ['when' => '*', 'go_to' => 'riepilogo']],
        ]);

        $this->assertSame($before, $this->jumpsOf('crif'));
    }

    public function test_una_modifica_che_lascia_domande_irraggiungibili_viene_annullata(): void
    {
        $node = $this->node('lavoro'); // solo da qui si arriva a «contratto» e, da lì, a «anzianita»
        $before = $this->jumpsOf('lavoro');

        $this->manager()->callTableAction('edit', $node, data: [
            'prompt' => $node->prompt, 'options' => $this->formOptions($node), 'jump_by' => 'answer',
            'jumps' => [['when' => 'pensionato', 'go_to' => 'ente_pensione'], ['when' => 'autonomo', 'go_to' => 'anni_attivita'], ['when' => '*', 'go_to' => 'impegni']],
        ]);

        $this->assertSame($before, $this->jumpsOf('lavoro'), 'il database resta com\'era');
        $this->assertSame([], app(FlowValidator::class)->flowErrors($this->flow()));
    }

    public function test_la_scheda_della_domanda_mostra_la_sezione_dei_salti(): void
    {
        $this->manager()->mountTableAction('edit', $this->node('impegni'))
            ->assertMountedActionModalSee(['Salti', 'I salti dipendono da']);
    }

    public function test_il_riepilogo_non_ha_salti_da_modificare(): void
    {
        $this->manager()->mountTableAction('edit', $this->node('riepilogo'))
            ->assertMountedActionModalDontSee('I salti dipendono da');
    }

    public function test_la_tabella_riassume_i_salti_di_ogni_domanda(): void
    {
        $this->manager()->assertTableColumnExists('salti')->assertTableColumnStateSet('salti', 'durata', record: $this->node('importo'));
    }
}
