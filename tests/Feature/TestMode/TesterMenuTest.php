<?php

namespace Tests\Feature\TestMode;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\LoanRequest;
use App\Models\User;
use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use App\Services\Flows\FlowCloner;
use Tests\Feature\Finanziamento\ConversationTestCase;

class TesterMenuTest extends ConversationTestCase
{
    private function withTestCopies(): void
    {
        $cloner = app(FlowCloner::class);
        foreach (['richiesta', 'perfezionamento', 'documenti'] as $code) {
            $cloner->createTestCopy(Flow::where('code', $code)->where('is_test', false)->firstOrFail());
        }
        $copy = Flow::where('code', 'richiesta')->where('is_test', true)->first();
        $copy->update(['header' => '🧪 VERSIONE DI PROVA']);
        $copy->nodes()->where('code', 'importo')->first()->update(['prompt' => 'PROVA: quale importo?']);
    }

    private function tester(string $number = '+39 333 111 2222'): User
    {
        return User::factory()->create(['whatsapp_number' => $number]);
    }

    public function test_chi_non_e_associato_a_un_utente_vede_solo_il_menu_normale(): void
    {
        $this->withTestCopies();

        $menu = $this->say('ciao')[0];

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato'], array_keys($menu->options));
    }

    public function test_chi_e_associato_a_un_utente_vede_anche_le_voci_di_prova(): void
    {
        $this->withTestCopies();
        $this->tester(); // l'agente di prova scrive da 393331112222

        $menu = $this->say('ciao')[0];

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato', 'test_richiedi', 'test_perfeziona', 'test_stato'], array_keys($menu->options));
        $this->assertSame('list', $menu->kind);
        foreach ($menu->options as $title) {
            $this->assertLessThanOrEqual(24, mb_strlen($title));
        }
        $this->assertStringContainsString('Prova', $menu->options['test_richiedi']);
    }

    public function test_si_mostrano_solo_le_prove_dei_percorsi_che_hanno_una_copia(): void
    {
        app(FlowCloner::class)->createTestCopy(Flow::where('code', 'richiesta')->where('is_test', false)->first());
        $this->tester();

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato', 'test_richiedi'], array_keys($this->say('ciao')[0]->options));
    }

    public function test_senza_copie_di_prova_neanche_l_utente_vede_voci_in_piu(): void
    {
        $this->tester();

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato'], array_keys($this->say('ciao')[0]->options));
    }

    public function test_la_voce_di_prova_avvia_la_versione_di_prova_in_ogni_messaggio(): void
    {
        $this->withTestCopies();
        $this->tester();

        $start = $this->say('#test_richiedi');
        $this->assertStringContainsString('VERSIONE DI PROVA', $this->bodies($start));
        $this->assertTrue((bool) Conversation::first()->is_test);

        $next = $this->say('#personale');
        $this->assertStringContainsString('PROVA: quale importo?', $this->bodies($next));
    }

    public function test_la_produzione_non_vede_le_modifiche_di_prova_nemmeno_dallo_stesso_numero(): void
    {
        $this->withTestCopies();
        $this->tester();

        $start = $this->say('#menu_richiedi');
        $this->assertStringNotContainsString('VERSIONE DI PROVA', $this->bodies($start));
        $this->assertFalse((bool) Conversation::first()->is_test);

        $this->assertStringNotContainsString('PROVA', $this->bodies($this->say('#personale')));
    }

    public function test_un_numero_non_associato_non_puo_avviare_la_prova_nemmeno_con_l_id_giusto(): void
    {
        $this->withTestCopies();

        $replies = $this->say('#test_richiedi');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(0, Conversation::count());
    }

    public function test_la_pratica_creata_in_prova_e_marcata_e_ha_il_codice_di_prova(): void
    {
        $this->withTestCopies();
        $this->tester();

        $this->say('#test_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso');
        $replies = $this->say('#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertTrue($loan->is_test);
        $this->assertMatchesRegularExpression('/^TST-\d{4}-0001$/', $loan->code);
        $this->assertStringContainsString($loan->code, $this->bodies($replies));
    }

    public function test_le_pratiche_vere_continuano_con_il_loro_codice_e_non_sono_di_prova(): void
    {
        $this->withTestCopies();
        $this->tester();

        $this->say('#test_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso', '#conferma');
        Conversation::query()->delete();
        $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso', '#conferma');

        $real = LoanRequest::where('is_test', false)->firstOrFail();
        $this->assertMatchesRegularExpression('/^FIN-\d{4}-0001$/', $real->code, 'la numerazione vera non la consumano le prove');
    }

    public function test_la_prova_di_stato_pratiche_usa_il_percorso_documenti_di_prova(): void
    {
        $this->withTestCopies();
        $this->tester();
        Flow::where('code', 'documenti')->where('is_test', true)->first()->update(['header' => 'HEADER DOCUMENTI PROVA']);
        LoanRequest::create(['code' => 'TST-2026-0001', 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'informativa_ricevuta', 'is_test' => true, 'answers' => []]);

        $this->assertStringContainsString('HEADER DOCUMENTI PROVA', $this->bodies($this->say('#test_stato')));
    }

    public function test_dopo_la_prova_la_modalita_non_resta_attiva_per_altri(): void
    {
        $this->withTestCopies();
        $this->tester();
        $this->say('#test_richiedi');

        // un altro agente, subito dopo, vede la produzione
        $other = new IncomingMessage('393339998888', 'interactive', '#menu_richiedi', 'menu_richiedi');
        $replies = app(ConversationEngine::class)->handle($other);

        $this->assertStringNotContainsString('VERSIONE DI PROVA', $this->bodies($replies));
    }
}
