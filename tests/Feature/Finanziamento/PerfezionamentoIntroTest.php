<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Fornitore;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\LoanRequest;
use App\Services\Flows\FlowValidator;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\DB;

class PerfezionamentoIntroTest extends ConversationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentCatalogSeeder::class);
        config(['app.url' => 'https://twillio.hassisto.com']);
    }

    private function loan(string $product = 'personale', array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => $product, 'status' => 'richiesta',
            'answers' => ['prodotto' => $product],
        ]);
    }

    public function test_il_link_dell_informativa_e_quello_della_company_del_produttore(): void
    {
        $prima = Company::create(['name' => 'Prima Spa']);
        $seconda = Company::create(['name' => 'Seconda Srl', 'address' => 'Via Verdi 2, Torino']);
        Fornitore::create(['name' => 'Mario Rossi', 'tel' => $this->agent, 'is_active' => true, 'tenant_company_id' => $seconda->id]);
        $this->loan();

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#si'));

        $this->assertStringContainsString('https://twillio.hassisto.com/privacy/'.$seconda->id, $body);
        $this->get('/privacy/'.$seconda->id)->assertOk()->assertSee('Seconda Srl')->assertSee('Via Verdi 2, Torino')->assertDontSee('Prima Spa');
        $this->assertNotSame($prima->id, $seconda->id);
    }

    public function test_dopo_la_conferma_della_pratica_il_bot_riassume_i_documenti_e_da_il_link_all_informativa(): void
    {
        $this->loan();

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');
        $body = $this->bodies($replies);

        $this->assertGreaterThanOrEqual(2, count($replies));
        $this->assertStringContainsString('FIN-2026-0007', $replies[0]->body);
        $this->assertStringContainsString('Prestito personale', $replies[0]->body);
        $this->assertStringContainsString('Obbligatori', $body);
        $this->assertStringContainsString('Documento d\'identità', $body);
        $this->assertStringContainsString('Carta d\'identità, passaporto o patente', $body, 'con la descrizione del catalogo');
        $this->assertStringContainsString('Facoltativi', $body);
        $this->assertStringContainsString('Estratto conto bancario', $body);
        $this->assertStringContainsString('https://twillio.hassisto.com/privacy', $body, 'senza company assegnata vale quella attuale');
        $this->assertStringContainsString('informativa', strtolower($body));
        $this->assertStringContainsString('invia l\'informativa privacy firmata', end($replies)->body, 'poi la domanda vera');
        $this->assertSame('informativa', Conversation::first()->node);
    }

    public function test_gli_integrativi_non_si_elencano_e_i_documenti_dipendono_dal_prodotto(): void
    {
        $this->loan('mutuo');

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#si'));

        $this->assertStringContainsString('Mutuo', $body);
        $this->assertStringContainsString('Preliminare di acquisto', $body);
        $this->assertStringNotContainsString('Perizia immobile', $body, 'è un integrativo');
        $this->assertStringNotContainsString('Contratto di lavoro', $body, 'è un integrativo');
    }

    public function test_non_restano_segnaposto_nel_messaggio(): void
    {
        $this->loan();

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#si'));

        $this->assertStringNotContainsString('{', $body);
        $this->assertStringNotContainsString('}', $body);
    }

    public function test_il_riepilogo_si_mostra_anche_se_l_informativa_e_gia_arrivata_e_si_prosegue_dai_documenti(): void
    {
        $this->loan('personale', ['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertStringContainsString('Obbligatori', $this->bodies($replies));
        $this->assertStringContainsString('documento d\'identità', end($replies)->body);
        $this->assertSame('doc_identita', Conversation::first()->node);
    }

    public function test_il_testo_si_personalizza_dalla_tabella_e_usa_i_segnaposto(): void
    {
        $this->loan();
        FlowNode::where('code', 'riepilogo_documenti')->update(['prompt' => 'PRATICA {codice} · {prodotto}\n\n{documenti}\n\nLink: {informativa_url}']);

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#si'));

        $this->assertStringContainsString('PRATICA FIN-2026-0007 · Prestito personale', $body);
        $this->assertStringContainsString('Link: https://twillio.hassisto.com/privacy', $body);
    }

    public function test_se_il_catalogo_non_ha_documenti_per_il_prodotto_lo_dice_e_prosegue(): void
    {
        DB::table('finanziamento_documents')->where('product', 'personale')->delete();
        $this->loan();

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertStringContainsString('nessun documento', strtolower($this->bodies($replies)));
        $this->assertSame('informativa', Conversation::first()->node);
    }

    public function test_dicendo_no_alla_pratica_non_si_mostra_il_riepilogo(): void
    {
        $this->loan();

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#no'));

        $this->assertStringNotContainsString('Obbligatori', $body);
        $this->assertSame('codice', Conversation::first()->node);
    }

    public function test_un_messaggio_non_puo_essere_la_prima_domanda_ne_senza_salto(): void
    {
        $flow = Flow::where('code', 'perfezionamento')->where('is_test', false)->first();
        $flow->update(['start' => 'riepilogo_documenti']);

        $errors = app(FlowValidator::class)->flowErrors($flow->fresh());

        $this->assertStringContainsString('messaggio', strtolower(implode(' ', $errors)));

        $flow->update(['start' => 'codice']);
        $node = FlowNode::where('flow_id', $flow->id)->where('code', 'riepilogo_documenti')->first();
        $node->jumps()->delete();
        $this->assertNotEmpty(app(FlowValidator::class)->nodeErrors($node->fresh(), $node->prompt, [], false));
    }

    public function test_la_migrazione_aggiunge_il_messaggio_a_un_albero_gia_importato_senza_duplicarlo(): void
    {
        $flow = Flow::where('code', 'perfezionamento')->where('is_test', false)->first();
        FlowNode::where('flow_id', $flow->id)->where('code', 'riepilogo_documenti')->first()->delete();
        FlowNode::where('flow_id', $flow->id)->where('code', 'conferma_pratica')->first()->jumps()->where('when_value', 'si')->update(['go_to' => 'informativa']);

        $migration = require database_path('migrations/2026_10_07_000017_add_riepilogo_documenti_to_perfezionamento.php');
        $migration->up();
        $migration->up();

        $nodes = FlowNode::where('flow_id', $flow->id)->where('code', 'riepilogo_documenti')->get();
        $this->assertCount(1, $nodes);
        $this->assertSame('message', $nodes->first()->type);
        $this->assertSame([['*', 'informativa']], $nodes->first()->jumps->map(fn ($j) => [$j->when_value, $j->go_to])->all());
        $this->assertSame('riepilogo_documenti', FlowNode::where('flow_id', $flow->id)->where('code', 'conferma_pratica')->first()->jumps->firstWhere('when_value', 'si')->go_to);
        $this->assertSame([], app(FlowValidator::class)->flowErrors($flow->fresh()));
        $order = FlowNode::where('flow_id', $flow->id)->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(array_search('conferma_pratica', $order) + 1, array_search('riepilogo_documenti', $order));
    }
}
