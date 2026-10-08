<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;

class RichiestaFlowTest extends ConversationTestCase
{
    public function test_un_testo_qualunque_mostra_il_menu(): void
    {
        $replies = $this->say('ciao');

        $this->assertCount(2, $replies, 'menu e link ai comandi per chi scrive la prima volta');
        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(['menu_richiedi', 'menu_modifica', 'menu_perfeziona', 'menu_stato'], array_keys($replies[0]->options));
        $this->assertSame(0, Conversation::count());
    }

    public function test_il_menu_si_sceglie_anche_digitando(): void
    {
        foreach (['1', 'Richiedi finanziamento', '  RICHIEDI '] as $input) {
            Conversation::query()->delete();
            $replies = $this->say($input);

            $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        }
    }

    public function test_cessione_del_quinto_completa_fino_al_codice(): void
    {
        $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60', '#dip_pub', '#indet', '#anz_10', '#red_2000', '#oltre15', '#no', '#no', '#no');

        $replies = $this->say('#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('quinto', $loan->product);
        $this->assertSame('richiesta', $loan->status);
        $this->assertSame('red_2000', $loan->answers['reddito']);
        $this->assertSame($this->agent, $loan->agent_wa_number);
        $this->assertMatchesRegularExpression('/^SEG-\d{4}-\d{4}$/', $loan->code);
        $this->assertStringContainsString($loan->code, $this->bodies($replies));
        $this->assertSame('completata', Conversation::first()->status);
    }

    public function test_il_riepilogo_elenca_le_risposte_in_chiaro(): void
    {
        $replies = $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60', '#dip_pub', '#indet', '#anz_10', '#red_2000', '#oltre15', '#no', '#no', '#no');

        $this->assertCount(2, $replies);
        $this->assertStringContainsString('Importo: 10.000 - 20.000 €', $replies[0]->body);
        $this->assertStringContainsString('Situazione lavorativa: Dipendente pubblico', $replies[0]->body);
        $this->assertSame(['conferma', 'modifica', 'annulla'], array_keys($replies[1]->options));
    }

    public function test_mutuo(): void
    {
        $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('mutuo', $loan->product);
        $this->assertSame('m240', $loan->answers['durata_mutuo']);
        $this->assertSame('ltv_80', $loan->answers['mutuo_ltv']);
    }

    public function test_leasing_prosegue_con_i_dati_aziendali_anonimi(): void
    {
        $this->say('#menu_richiedi', '#leasing', '#auto', '#g_100k', '#m48', '#si', '#no', '#srl', '#anz_3', '#fat_500', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('leasing', $loan->product);
        $this->assertSame('srl', $loan->answers['az_forma']);
        $this->assertArrayNotHasKey('az_finalita', $loan->answers);
    }

    public function test_aziendale(): void
    {
        $this->say('#menu_richiedi', '#aziendale', '#srl', '#anz_10', '#fat_2m', '#g_200k', '#m60', '#investimenti', '#fondo_pmi', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('fondo_pmi', $loan->answers['az_garanzie']);
        $this->assertSame('g_200k', $loan->answers['az_importo']);
    }

    public function test_finalizzato_chiede_bene_e_anticipo(): void
    {
        $this->say('#menu_richiedi', '#finalizzato', '#imp_10k', '#m36', '#altro', '#no', '#no', '#auto_usata', '#imp_10k', '#si', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('auto_usata', $loan->answers['bene']);
        $this->assertSame('si', $loan->answers['anticipo']);
    }

    public function test_dati_identificativi_sono_rifiutati_e_la_domanda_si_ripete(): void
    {
        $this->say('#menu_richiedi', '#personale');

        foreach (['RSSMRA80A01H501U', 'rssmra80a01h501u', 'mario@example.com', '333 123 4567'] as $input) {
            $replies = $this->say($input);

            $this->assertStringContainsString('Non inserire dati identificativi', $this->bodies($replies));
            $this->assertStringContainsString('Quale importo', $this->bodies($replies));
            $this->assertSame('importo', Conversation::first()->node);
        }
        $this->assertArrayNotHasKey('importo', Conversation::first()->data);
    }

    public function test_risposta_non_valida_ripete_la_domanda(): void
    {
        $this->say('#menu_richiedi', '#personale');

        $replies = $this->say('boh');

        $this->assertStringContainsString('Scegli una delle opzioni', $this->bodies($replies));
        $this->assertStringContainsString('Quale importo', $this->bodies($replies));
        $this->assertSame('importo', Conversation::first()->node);
    }

    public function test_la_risposta_si_puo_digitare(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#dip_priv', '#det', '#anz_1', '#red_1000');

        foreach (['  SÌ ', 'sì', 'si', '1'] as $input) {
            Conversation::first()->update(['node' => 'impegni', 'data' => ['prodotto' => 'personale']]);
            $this->say($input);

            $this->assertSame('rata', Conversation::first()->node, "input: '$input'");
        }
    }

    public function test_indietro_torna_alla_domanda_precedente(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k');

        $replies = $this->say('indietro');

        $this->assertStringContainsString('Quale importo', $this->bodies($replies));
        $this->assertSame('importo', Conversation::first()->node);
        $this->assertArrayNotHasKey('importo', Conversation::first()->data);
    }

    public function test_indietro_alla_prima_domanda_resta_fermo(): void
    {
        $this->say('#menu_richiedi');

        $replies = $this->say('indietro');

        $this->assertStringContainsString('prima domanda', $this->bodies($replies));
        $this->assertSame('prodotto', Conversation::first()->node);
    }

    public function test_annulla_e_menu_chiudono_la_conversazione(): void
    {
        $this->say('#menu_richiedi', '#mutuo');

        $this->say('annulla');
        $this->assertSame('annullata', Conversation::first()->status);

        $this->say('#menu_richiedi');
        $replies = $this->say('menu');
        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(2, Conversation::where('status', 'annullata')->count());
    }

    public function test_modifica_ricomincia_il_ramo(): void
    {
        $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso');

        $replies = $this->say('#modifica');

        $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        $this->assertSame([], Conversation::first()->data);
        $this->assertSame(0, LoanRequest::count());
    }

    public function test_messaggio_non_supportato_non_rompe_la_conversazione(): void
    {
        $this->say('#menu_richiedi');
        $engine = app(ConversationEngine::class);

        $replies = $engine->handle(new IncomingMessage($this->agent, 'unsupported'));

        $this->assertStringContainsString('non è supportato', $this->bodies($replies));
        $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        $this->assertSame('prodotto', Conversation::first()->node);
    }

    public function test_agenti_diversi_hanno_conversazioni_separate(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $other = new IncomingMessage('393339998888', 'interactive', '#menu_richiedi', 'menu_richiedi');
        app(ConversationEngine::class)->handle($other);

        $this->assertSame(2, Conversation::count());
        $this->assertSame('mutuo_scopo', Conversation::where('wa_number', $this->agent)->first()->node);
        $this->assertSame('prodotto', Conversation::where('wa_number', '393339998888')->first()->node);
    }

    public function test_un_saluto_a_un_percorso_lasciato_alla_prima_domanda_riporta_al_menu(): void
    {
        $this->say('#menu_richiedi');
        $this->assertSame('prodotto', Conversation::first()->node);

        foreach (['ciao', 'Buongiorno', '  SALVE '] as $greeting) {
            Conversation::query()->update(['status' => 'attiva']);
            $replies = $this->say($greeting);

            $this->assertSame('list', $replies[0]->kind, $greeting);
            $this->assertSame(['menu_richiedi', 'menu_modifica', 'menu_perfeziona', 'menu_stato'], array_keys($replies[0]->options), $greeting);
            $this->assertSame('annullata', Conversation::latest('id')->first()->status);
        }
    }

    public function test_un_saluto_a_meta_percorso_non_lo_interrompe(): void
    {
        $this->say('#menu_richiedi', '#personale');

        $replies = $this->say('ciao');

        $this->assertSame('attiva', Conversation::first()->status);
        $this->assertSame('importo', Conversation::first()->node);
        $this->assertStringContainsString('Scegli una delle opzioni', $this->bodies($replies));
    }

    public function test_i_comandi_si_scrivono_anche_con_la_barra(): void
    {
        $this->say('#menu_richiedi', '#personale');

        $replies = $this->say('/menu');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame('annullata', Conversation::first()->status);
    }

    public function test_menu_porta_il_link_alla_sintesi_dei_comandi(): void
    {
        config(['app.url' => 'https://twillio.hassisto.com']);

        $replies = $this->say('/menu');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertStringContainsString('https://twillio.hassisto.com/comandi', $replies[1]->body);

        $this->say('#menu_richiedi', 'annulla');
        $this->assertCount(1, $this->say('ciao'), 'chi ha già scritto riceve solo il menu');
    }

    public function test_il_saluto_di_un_nuovo_utente_porta_il_link_ai_comandi(): void
    {
        config(['app.url' => 'https://twillio.hassisto.com']);

        $replies = $this->say('ciao');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertStringContainsString('https://twillio.hassisto.com/comandi', $replies[1]->body);
    }

    public function test_help_elenca_i_comandi_e_ripropone_la_domanda_in_corso(): void
    {
        $this->say('#menu_richiedi', '#personale');

        foreach (['help', '/help', 'Aiuto'] as $word) {
            $replies = $this->say($word);

            $this->assertStringContainsString('*menu*', $replies[0]->body, $word);
            $this->assertStringContainsString('*annulla*', $replies[0]->body, $word);
            $this->assertStringContainsString('/menu', $replies[0]->body, $word);
            $this->assertStringContainsString('Quale importo', end($replies)->body, $word);
            $this->assertSame('importo', Conversation::first()->node, 'la conversazione non cambia');
        }
    }

    public function test_help_senza_conversazione_mostra_comandi_e_menu(): void
    {
        $replies = $this->say('/help');

        $this->assertStringContainsString('*help*', $replies[0]->body);
        $this->assertSame('list', end($replies)->kind);
        $this->assertSame(0, Conversation::count());
    }

    public function test_il_messaggio_per_rompere_il_ghiaccio_apre_il_percorso(): void
    {
        $replies = $this->say('Richiedi Finanziamento');

        $this->assertSame('richiesta', Conversation::first()->flow);
        $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
    }
}
