<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\LoanRequest;
use App\Services\Flows\FlowRepository;
use App\Services\Flows\FlowValidator;

class ModificaPreventivoTest extends ConversationTestCase
{
    private const FLOW = ['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500', '#no', '#no', '#conferma'];

    private function quote(array $steps = self::FLOW): LoanRequest
    {
        $this->say(...$steps);

        return LoanRequest::latest('id')->first();
    }

    public function test_la_configurazione_rende_modificabili_importo_e_durata(): void
    {
        $repo = app(FlowRepository::class);

        $this->assertTrue($repo->node('richiesta', 'importo')['can_modify']);
        $this->assertTrue($repo->node('richiesta', 'durata')['can_modify']);
        $this->assertArrayNotHasKey('can_modify', $repo->node('richiesta', 'prodotto'));
        $this->assertArrayNotHasKey('can_modify', $repo->node('richiesta', 'lavoro'));
    }

    public function test_alla_fine_del_preventivo_ci_sono_modifica_e_menu(): void
    {
        $replies = $this->say(...self::FLOW);

        $last = end($replies);
        $this->assertSame('buttons', $last->kind);
        $this->assertSame(['modifica:'.LoanRequest::first()->code => 'Modifica preventivo', 'vai_menu' => 'Vai al menu'], $last->options);
        $this->assertStringContainsString('Codice pratica', $last->body);
    }

    public function test_senza_domande_modificabili_per_quel_prodotto_non_c_e_il_pulsante(): void
    {
        $replies = $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso', '#conferma');

        $this->assertSame(['vai_menu'], array_keys(end($replies)->options));
    }

    public function test_modifica_chiede_solo_i_dati_modificabili_con_il_valore_attuale(): void
    {
        $loan = $this->quote();

        $replies = $this->say('#modifica:'.$loan->code);

        $this->assertStringContainsString('Modifica del preventivo '.$loan->code, $replies[0]->body);
        $this->assertStringContainsString('Valore attuale: *1.000 - 5.000 €*', end($replies)->body);
        $this->assertArrayHasKey('_keep', end($replies)->options);
        $this->assertSame('importo', Conversation::where('status', 'attiva')->first()->node);
        $this->assertSame('personale', Conversation::where('status', 'attiva')->first()->data['prodotto'], 'i dati sono clonati');
    }

    public function test_si_cambia_un_dato_e_si_mantiene_l_altro_poi_nasce_un_nuovo_preventivo(): void
    {
        $original = $this->quote();

        $this->say('#modifica:'.$original->code, '#imp_20k');
        $this->assertSame('durata', Conversation::where('status', 'attiva')->first()->node);

        $summary = $this->say('#_keep');
        $this->assertSame('riepilogo', Conversation::where('status', 'attiva')->first()->node, 'le altre domande non si richiedono');
        $this->assertStringContainsString('10.000 - 20.000', $summary[0]->body);

        $this->say('#conferma');

        $new = LoanRequest::latest('id')->first();
        $this->assertNotSame($original->id, $new->id);
        $this->assertSame($original->id, $new->parent_id);
        $this->assertSame('imp_20k', $new->answers['importo']);
        $this->assertSame('m24', $new->answers['durata'], 'mantenuta');
        $this->assertSame('dip_priv', $new->answers['lavoro'], 'clonata');
        $this->assertArrayNotHasKey('_modify', $new->answers);
        $this->assertSame('imp_5k', $original->fresh()->answers['importo'], 'l\'originale non cambia');
        $this->assertNotSame($original->code, $new->code);
    }

    public function test_si_puo_mantenere_scrivendo_ok(): void
    {
        $loan = $this->quote();

        $this->say('#modifica:'.$loan->code, 'ok', 'Ok');

        $this->assertSame('riepilogo', Conversation::where('status', 'attiva')->first()->node);
    }

    public function test_il_nuovo_preventivo_si_puo_modificare_ancora(): void
    {
        $loan = $this->quote();
        $this->say('#modifica:'.$loan->code, '#imp_10k', '#_keep');
        $replies = $this->say('#conferma');

        $second = LoanRequest::latest('id')->first();
        $this->assertArrayHasKey('modifica:'.$second->code, end($replies)->options);
    }

    public function test_ricominciare_in_modifica_torna_al_preventivo_di_partenza_e_non_a_zero(): void
    {
        $loan = $this->quote();
        $this->say('#modifica:'.$loan->code, '#imp_20k', '#_keep');

        $replies = $this->say('#modifica');

        $conv = Conversation::where('status', 'attiva')->first();
        $this->assertSame('importo', $conv->node);
        $this->assertSame('imp_5k', $conv->data['importo'], 'torna ai valori del preventivo di partenza');
        $this->assertSame('personale', $conv->data['prodotto']);
        $this->assertStringContainsString('Valore attuale: *1.000 - 5.000 €*', end($replies)->body);
    }

    public function test_non_si_modifica_il_preventivo_di_un_altro_agente(): void
    {
        $other = LoanRequest::create(['code' => 'FIN-2026-0099', 'agent_wa_number' => '390000000000', 'product' => 'personale', 'status' => 'richiesta', 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_5k']]);

        $replies = $this->say('#modifica:'.$other->code);

        $this->assertStringContainsString('Non trovo questo preventivo', $replies[0]->body);
        $this->assertSame(0, Conversation::count());
        $this->assertSame(1, LoanRequest::count());
    }

    public function test_il_pulsante_vai_al_menu_mostra_il_menu(): void
    {
        $this->quote();

        $replies = $this->say('#vai_menu');

        $this->assertSame(['menu_richiedi', 'menu_modifica', 'menu_perfeziona', 'menu_stato'], array_keys($replies[0]->options));
    }

    public function test_un_dato_non_modificabile_che_cambia_il_percorso_chiede_le_domande_nuove(): void
    {
        FlowNode::where('code', 'lavoro')->update(['can_modify' => true]);
        $loan = $this->quote();

        $this->say('#modifica:'.$loan->code, '#_keep', '#_keep');
        $this->assertSame('lavoro', Conversation::where('status', 'attiva')->first()->node);

        $this->say('#pensionato');

        // «pensionato» porta a domande che il preventivo di partenza non aveva: vanno chieste.
        $this->assertSame('ente_pensione', Conversation::where('status', 'attiva')->first()->node);
    }

    public function test_solo_le_domande_a_scelta_di_richiesta_possono_essere_modificabili(): void
    {
        $validator = app(FlowValidator::class);
        $text = FlowNode::whereHas('flow', fn ($q) => $q->where('code', 'perfezionamento'))->where('type', 'text')->first();
        $choice = FlowNode::where('code', 'importo')->first();

        $this->assertContains('Solo le domande a scelta del percorso di richiesta possono essere modificabili.', $validator->nodeErrors($text, $text->prompt, [], false, [], null, null, true));
        $this->assertNotContains('Solo le domande a scelta del percorso di richiesta possono essere modificabili.', $validator->nodeErrors($choice, $choice->prompt, $choice->options->pluck('title', 'code')->all(), false, [], null, null, true));
    }
}
