<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;

class FlowHeaderSkipTest extends ConversationTestCase
{
    private function node(string $flow, string $code): FlowNode
    {
        return FlowNode::whereHas('flow', fn ($q) => $q->where('code', $flow))->where('code', $code)->firstOrFail();
    }

    public function test_l_intestazione_si_mostra_all_inizio_del_dialogo(): void
    {
        Flow::where('code', 'richiesta')->update(['header' => "📋 *Richiesta di finanziamento*\nSolo scelte, nessun dato del cliente."]);

        $replies = $this->say('#menu_richiedi');

        $this->assertCount(2, $replies);
        $this->assertSame('text', $replies[0]->kind);
        $this->assertStringContainsString('Richiesta di finanziamento', $replies[0]->body);
        $this->assertStringContainsString('Che tipo di finanziamento', $replies[1]->body);
    }

    public function test_senza_intestazione_o_con_intestazione_vuota_non_si_aggiunge_nulla(): void
    {
        $this->assertCount(1, $this->say('#menu_richiedi'));

        Conversation::query()->delete();
        Flow::where('code', 'richiesta')->update(['header' => "  \n "]);
        $this->assertCount(1, $this->say('#menu_richiedi'));
    }

    public function test_l_intestazione_si_mostra_solo_all_inizio_non_dopo_ogni_risposta_ne_dopo_indietro(): void
    {
        Flow::where('code', 'richiesta')->update(['header' => 'INTESTAZIONE']);

        $this->say('#menu_richiedi');
        $afterAnswer = $this->say('#personale');
        $afterBack = $this->say('indietro');

        $this->assertStringNotContainsString('INTESTAZIONE', $this->bodies($afterAnswer));
        $this->assertStringNotContainsString('INTESTAZIONE', $this->bodies($afterBack));
    }

    public function test_ogni_percorso_ha_la_sua_intestazione(): void
    {
        Flow::where('code', 'perfezionamento')->update(['header' => 'HEADER PERFEZIONA']);
        Flow::where('code', 'documenti')->update(['header' => 'HEADER DOCUMENTI']);
        LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'informativa_ricevuta', 'answers' => []]);

        $this->assertStringContainsString('HEADER PERFEZIONA', $this->bodies($this->say('#menu_perfeziona', '#perfeziona:FIN-2026-0001')));
        Conversation::query()->delete();
        $this->assertStringContainsString('HEADER DOCUMENTI', $this->bodies($this->say('#menu_stato')));
        Conversation::query()->delete();
        $this->assertStringNotContainsString('HEADER', $this->bodies($this->say('#menu_richiedi')));
    }

    public function test_una_domanda_saltabile_si_salta_scrivendo_salta_e_non_salva_nulla(): void
    {
        $this->node('richiesta', 'importo')->update(['skippable' => true]);
        $this->say('#menu_richiedi', '#personale');

        $replies = $this->say('Salta');

        $this->assertSame('durata', Conversation::first()->node);
        $this->assertArrayNotHasKey('importo', Conversation::first()->data);
        $this->assertStringContainsString('Su quale durata', $this->bodies($replies));
    }

    public function test_una_domanda_saltabile_mostra_il_suggerimento(): void
    {
        $this->node('richiesta', 'importo')->update(['skippable' => true]);

        $body = $this->bodies($this->say('#menu_richiedi', '#personale'));

        $this->assertStringContainsString('Scrivi «salta» per saltare', $body);
    }

    public function test_una_domanda_non_saltabile_non_accetta_salta(): void
    {
        $this->say('#menu_richiedi', '#personale');

        $body = $this->bodies($this->say('salta'));

        $this->assertStringContainsString('Scegli una delle opzioni', $body);
        $this->assertStringNotContainsString('Scrivi «salta»', $body);
        $this->assertSame('importo', Conversation::first()->node);
    }

    public function test_un_testo_libero_saltabile_passa_al_nodo_successivo(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $this->node('perfezionamento', 'residenza')->update(['skippable' => true]);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario');
        $this->assertSame('residenza', Conversation::first()->node);

        $this->say('salta');

        $this->assertSame('stato_civile', Conversation::first()->node);
        $this->assertArrayNotHasKey('residenza', Conversation::first()->data);
    }

    public function test_saltare_una_domanda_a_salti_condizionati_segue_l_uscita_predefinita(): void
    {
        $this->node('richiesta', 'crif')->update(['skippable' => true]);
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500', '#no');
        $this->assertSame('crif', Conversation::first()->node);

        $this->say('salta');

        $this->assertSame('riepilogo', Conversation::first()->node);
        $this->assertArrayNotHasKey('crif', Conversation::first()->data);
    }

    public function test_senza_uscita_predefinita_la_domanda_non_e_saltabile_anche_se_il_flag_e_attivo(): void
    {
        $this->node('richiesta', 'impegni')->update(['skippable' => true]); // salti si/no, nessun '*'
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500');
        $this->assertSame('impegni', Conversation::first()->node);

        $body = $this->bodies($this->say('salta'));

        $this->assertStringContainsString('Scegli una delle opzioni', $body);
        $this->assertSame('impegni', Conversation::first()->node);
    }

    public function test_il_documento_di_reddito_resta_saltabile_come_prima(): void
    {
        $this->assertTrue($this->node('perfezionamento', 'doc_reddito')->skippable);
        $this->assertFalse($this->node('perfezionamento', 'doc_identita')->skippable);
    }
}
