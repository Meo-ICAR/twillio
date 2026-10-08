<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use App\Services\Flows\FlowCloner;
use Database\Seeders\DocumentCatalogSeeder;

class ElencoPraticheMenuTest extends ConversationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(string $code, string $status = 'richiesta', array $o = []): LoanRequest
    {
        return LoanRequest::create($o + ['code' => $code, 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => $status,
            'answers' => ['prodotto' => 'personale', 'importo' => 'imp_5k', 'durata' => 'm24']]);
    }

    public function test_il_menu_ha_modifica_preventivo_e_si_sceglie_anche_scrivendo(): void
    {
        $this->loan('FIN-2026-0001');

        $this->assertArrayHasKey('menu_modifica', $this->say('ciao')[0]->options);
        foreach (['2', 'modifica', 'Modifica preventivo'] as $text) {
            $this->assertArrayHasKey('modifica:FIN-2026-0001', $this->say($text)[0]->options, $text);
        }
    }

    public function test_modifica_elenca_gli_ultimi_5_preventivi_non_perfezionati_dell_agente(): void
    {
        foreach (range(1, 7) as $n) {
            $this->loan(sprintf('FIN-2026-%04d', $n));
        }
        $this->loan('FIN-2026-0008', 'perfezionata');
        $this->loan('FIN-2026-0009', 'richiesta', ['agent_wa_number' => '390000000000']);
        $this->loan('TST-2026-0001', 'richiesta', ['is_test' => true]);

        $reply = $this->say('#menu_modifica')[0];

        $this->assertSame(['modifica:FIN-2026-0007', 'modifica:FIN-2026-0006', 'modifica:FIN-2026-0005', 'modifica:FIN-2026-0004', 'modifica:FIN-2026-0003'], array_keys($reply->options));
        $this->assertSame('list', $reply->kind);
        foreach ($reply->options as $title) {
            $this->assertLessThanOrEqual(24, mb_strlen($title));
        }
        $this->assertStringStartsWith('FIN-2026-0007 · ', $reply->options['modifica:FIN-2026-0007']);
        $this->assertSame(0, Conversation::count(), 'la scelta non apre ancora nessuna conversazione');
    }

    public function test_senza_preventivi_lo_dice_e_torna_al_menu(): void
    {
        $this->loan('FIN-2026-0001', 'perfezionata');

        $replies = $this->say('#menu_modifica');

        $this->assertStringContainsString('Non hai preventivi da modificare', $replies[0]->body);
        $this->assertSame('list', end($replies)->kind);
    }

    public function test_scegliendo_un_preventivo_parte_la_modifica(): void
    {
        $this->loan('FIN-2026-0001');

        $replies = $this->say('#menu_modifica', '#modifica:FIN-2026-0001');

        $this->assertStringContainsString('Modifica del preventivo FIN-2026-0001', $replies[0]->body);
        $this->assertSame('importo', Conversation::first()->node);
    }

    public function test_perfeziona_elenca_i_preventivi_recenti_e_le_pratiche_con_documenti_mancanti(): void
    {
        foreach (range(1, 4) as $n) {
            $this->loan(sprintf('FIN-2026-%04d', $n));
        }
        $conMancanti = $this->loan('FIN-2026-0005', 'perfezionata');
        PraticaDocument::populate($conMancanti);
        $completa = $this->loan('FIN-2026-0006', 'perfezionata');
        PraticaDocument::populate($completa)->where('requirement', 'obbligatorio')->each->update(['status' => 'ok']);
        $this->loan('FIN-2026-0007', 'richiesta', ['agent_wa_number' => '390000000000']);

        $reply = $this->say('#menu_perfeziona')[0];

        $this->assertSame(
            ['perfeziona:FIN-2026-0005', 'perfeziona:FIN-2026-0004', 'perfeziona:FIN-2026-0003', 'perfeziona:FIN-2026-0002', 'perfeziona:FIN-2026-0001'],
            array_keys($reply->options),
            'la pratica perfezionata completa e quella di un altro agente non ci sono'
        );
    }

    public function test_perfeziona_non_mostra_piu_di_5_voci(): void
    {
        foreach (range(1, 9) as $n) {
            $this->loan(sprintf('FIN-2026-%04d', $n));
        }

        $this->assertCount(5, $this->say('#menu_perfeziona')[0]->options);
    }

    public function test_scegliendo_un_preventivo_si_perfeziona_senza_scrivere_il_codice(): void
    {
        $loan = $this->loan('FIN-2026-0001');
        Flow::where('code', 'perfezionamento')->update(['header' => 'HEADER']);

        $replies = $this->say('#menu_perfeziona', '#perfeziona:FIN-2026-0001');

        $this->assertSame('conferma_pratica', Conversation::first()->node);
        $this->assertSame($loan->id, Conversation::first()->loan_request_id);
        $this->assertSame('in_attesa_informativa', $loan->fresh()->status);
        $this->assertStringContainsString('HEADER', $replies[0]->body);
        $this->assertStringContainsString('È la pratica giusta?', $this->bodies($replies));
        $this->assertStringContainsString('Importo', $this->bodies($replies));

        $this->say('#si');
        $this->assertSame('informativa', Conversation::first()->node);
    }

    public function test_una_pratica_perfezionata_con_documenti_mancanti_porta_al_caricamento(): void
    {
        $loan = $this->loan('FIN-2026-0001', 'perfezionata', ['privacy_received_at' => now()]);
        PraticaDocument::populate($loan);

        $replies = $this->say('#menu_perfeziona', '#perfeziona:FIN-2026-0001');

        $this->assertSame('documenti', Conversation::first()->flow);
        $this->assertSame('dettaglio', Conversation::first()->node);
        $this->assertStringContainsString('Obbligatori', $this->bodies($replies));
        $this->assertArrayHasKey('carica', end($replies)->options);
        $this->assertSame('perfezionata', $loan->fresh()->status);
    }

    public function test_si_puo_ancora_scrivere_il_codice(): void
    {
        $this->loan('FIN-2026-0001');

        $replies = $this->say('fin-2026-0001');

        $this->assertSame('conferma_pratica', Conversation::first()->node);
        $this->assertStringContainsString('È la pratica giusta?', $this->bodies($replies));
    }

    public function test_un_altro_agente_non_puo_perfezionare_la_pratica_altrui(): void
    {
        $this->loan('FIN-2026-0001', 'richiesta', ['agent_wa_number' => '390000000000']);

        $replies = $this->say('#perfeziona:FIN-2026-0001');

        $this->assertStringContainsString('Codice non trovato', $replies[0]->body);
        $this->assertSame(0, Conversation::count());
        $this->assertSame('richiesta', LoanRequest::first()->status);
    }

    public function test_senza_pratiche_da_perfezionare_lo_dice(): void
    {
        $this->loan('FIN-2026-0001', 'perfezionata');

        $replies = $this->say('#menu_perfeziona');

        $this->assertStringContainsString('Non hai preventivi da perfezionare', $replies[0]->body);
        $this->assertSame(0, Conversation::count());
    }

    public function test_la_voce_di_prova_elenca_solo_le_pratiche_di_prova(): void
    {
        $this->loan('FIN-2026-0001');
        $this->loan('TST-2026-0001', 'richiesta', ['is_test' => true]);
        User::factory()->create(['whatsapp_number' => $this->agent]);
        app(FlowCloner::class)->createTestCopy(Flow::where('code', 'perfezionamento')->where('is_test', false)->first());

        $reply = $this->say('#test_perfeziona')[0];

        $this->assertSame(['perfeziona:TST-2026-0001'], array_keys($reply->options));
    }
}
