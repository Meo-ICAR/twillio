<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PerfezionamentoFlowTest extends ConversationTestCase
{
    private function loan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'richiesta', 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k'],
        ]);
    }

    private function personal(): array
    {
        return ['Mario', 'Rossi', 'rssmra80a01h501u', '01/01/1980', 'Roma', 'Via Roma 1, 00100 Roma', '#coniugato', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', 'it60 x054 2811 1010 0000 0123 456'];
    }

    public function test_percorso_completo_con_informativa_e_dati_cifrati(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'fin-2026-0007');
        $replies = $this->say('#si');
        $this->assertStringContainsString('informativa privacy firmata', $this->bodies($replies));

        $this->say('media:M1:application/pdf', ...$this->personal());
        $this->say('ACME Srl', '01/03/2015', 'media:D1:image/jpeg', 'media:D2:image/jpeg');
        $summary = $this->say('salta');

        $this->assertStringContainsString('✅ Documento d\'identità', $summary[0]->body);
        $this->assertStringContainsString('➖ Documento di reddito', $summary[0]->body);

        $replies = $this->say('#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertNotNull($loan->perfected_at);
        $this->assertNotNull($loan->privacy_received_at);
        $this->assertSame('Mario', $loan->personal['nome']);
        $this->assertSame('RSSMRA80A01H501U', $loan->personal['codice_fiscale']);
        $this->assertSame('IT60X0542811101000000123456', $loan->personal['iban']);
        $this->assertSame('+393331234567', $loan->personal['telefono']);
        $this->assertStringNotContainsString('Mario', DB::table('loan_requests')->value('personal'));
        $this->assertSame(3, $loan->attachments()->count());
        foreach ($loan->attachments as $a) {
            Storage::disk('local')->assertExists($a->path);
        }
        $this->assertStringContainsString('perfezionata', $this->bodies($replies));
    }

    public function test_nessun_dato_personale_prima_dell_informativa(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('Mario');

        $this->assertStringContainsString('Invia una foto o un PDF', $this->bodies($replies));
        $this->assertSame('informativa', Conversation::first()->node);
        $this->assertSame([], Conversation::first()->data);
        $this->assertSame('in_attesa_informativa', LoanRequest::first()->status);
        $this->assertNull(LoanRequest::first()->privacy_received_at);
    }

    public function test_dopo_l_informativa_si_sblocca_e_traccia_la_ricezione(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:M1:image/jpeg');

        $this->assertStringContainsString('Nome del cliente', $this->bodies($replies));
        $loan = LoanRequest::first();
        $this->assertSame('informativa_ricevuta', $loan->status);
        $this->assertNotNull($loan->privacy_received_at);
        $this->assertSame('informativa', $loan->attachments()->first()->kind);
    }

    public function test_informativa_gia_ricevuta_viene_saltata(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertStringContainsString('Nome del cliente', $this->bodies($replies));
    }

    public function test_formato_file_non_accettato(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:M1:video/mp4');

        $this->assertStringContainsString('Formato non accettato', $this->bodies($replies));
        $this->assertSame('informativa', Conversation::first()->node);
    }

    public function test_download_fallito(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:FAIL:image/jpeg');

        $this->assertStringContainsString('Non sono riuscito a scaricare', $this->bodies($replies));
        $this->assertNull(LoanRequest::first()->privacy_received_at);
    }

    public function test_codici_non_validi(): void
    {
        $this->loan();
        $this->loan(['code' => 'FIN-2026-0008', 'agent_wa_number' => '393339998888']);
        $this->loan(['code' => 'FIN-2026-0009', 'status' => 'perfezionata']);
        $this->say('#menu_perfeziona');

        $this->assertStringContainsString('Codice non trovato', $this->bodies($this->say('FIN-2026-9999')));
        $this->assertStringContainsString('Codice non trovato', $this->bodies($this->say('FIN-2026-0008')));
        $this->assertStringContainsString('già stata perfezionata', $this->bodies($this->say('FIN-2026-0009')));
        $this->assertSame('codice', Conversation::first()->node);
    }

    public function test_la_conferma_mostra_il_riepilogo_anonimo_e_no_riparte(): void
    {
        $this->loan();
        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007');

        $this->assertStringContainsString('Importo: 5.000 - 10.000 €', $this->bodies($replies));
        $this->assertStringContainsString('È la pratica giusta?', $this->bodies($replies));
        $this->assertStringContainsString('Inserisci il codice', $this->bodies($this->say('#no')));
    }

    public function test_dati_non_validi_vengono_rifiutati(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'Mario', 'Rossi');

        $this->assertStringContainsString('Codice fiscale non valido', $this->bodies($this->say('ABC')));
        $this->say('RSSMRA80A01H501U');
        $this->assertStringContainsString('Data non valida', $this->bodies($this->say('31/02/1980')));
        $this->assertStringContainsString('Data non valida', $this->bodies($this->say('1980-01-01')));
        $this->say('01/01/1980', 'Roma', 'Via Roma 1', '#celibe', '#ci');
        $this->assertSame('documento_numero', Conversation::first()->node);
    }

    public function test_un_testo_dove_serve_un_file_viene_rifiutato_ma_il_reddito_si_salta(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');
        Conversation::first()->update(['node' => 'doc_identita', 'data' => []]);

        $this->assertStringContainsString('Invia una foto o un PDF', $this->bodies($this->say('ecco')));
        $this->say('media:D1:image/png', 'media:D2:image/png');
        $this->assertSame('doc_reddito', Conversation::first()->node);
        $this->say('salta');
        $this->assertSame('riepilogo_p', Conversation::first()->node);
    }

    public function test_prodotti_aziendali_chiedono_ragione_sociale_e_partita_iva(): void
    {
        $this->loan(['product' => 'aziendale', 'answers' => ['prodotto' => 'aziendale'],
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', ...$this->personal());
        $this->assertSame('ragione_sociale', Conversation::first()->node);

        $this->assertStringContainsString('11 cifre', $this->bodies($this->say('Acme Srl', '123')));
        $this->say('123 4567 8901');
        $this->assertSame('doc_identita', Conversation::first()->node);
    }

    public function test_stato_pratiche_elenca_solo_quelle_dell_agente(): void
    {
        $this->assertStringContainsString('nessuna pratica', $this->bodies($this->say('#menu_stato')));

        $this->loan();
        $this->loan(['code' => 'FIN-2026-0008', 'agent_wa_number' => '393339998888']);

        $text = $this->bodies($this->say('3'));
        $this->assertStringContainsString('FIN-2026-0007 · Prestito personale · richiesta', $text);
        $this->assertStringNotContainsString('FIN-2026-0008', $text);
    }

    public function test_dopo_24_ore_chiede_se_continuare(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $conv = Conversation::first();
        $conv->updated_at = now()->subDays(2);
        $conv->saveQuietly();

        $replies = $this->say('#prima');
        $this->assertStringContainsString('più di 24 ore', $this->bodies($replies));
        $this->assertSame('mutuo_scopo', Conversation::first()->node);

        $replies = $this->say('#resume_si');
        $this->assertStringContainsString('scopo del mutuo', $this->bodies($replies));

        $this->say('#prima');
        $this->assertSame('mutuo_valore', Conversation::first()->node);
    }

    public function test_dopo_24_ore_si_puo_ricominciare(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $conv = Conversation::first();
        $conv->updated_at = now()->subDays(2);
        $conv->saveQuietly();

        $this->say('ciao');
        $replies = $this->say('#resume_no');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame('annullata', Conversation::first()->status);
    }

    public function test_codice_fiscale_omocodico_e_accettato(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'Mario', 'Rossi', 'RSSMRA8LT01H501U');

        $this->assertSame('data_nascita', Conversation::first()->node);
    }

    public function test_risposta_digitata_alla_ripresa_dopo_24_ore(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $conv = Conversation::first();
        $conv->updated_at = now()->subDays(2);
        $conv->saveQuietly();
        $this->say('ciao');

        $replies = $this->say('Continua');

        $this->assertStringContainsString('scopo del mutuo', $this->bodies($replies));
        $this->say('#prima');
        $this->assertSame('mutuo_valore', Conversation::first()->node);
    }

    public function test_un_nodo_rimosso_dalla_config_non_blocca_l_agente(): void
    {
        $this->say('#menu_richiedi');
        Conversation::first()->update(['node' => 'nodo_rimosso']);

        $replies = $this->say('ciao');

        $this->assertSame('list', end($replies)->kind);
        $this->assertSame('annullata', Conversation::first()->status);
    }

    public function test_i_dati_della_conversazione_vengono_cancellati_alla_chiusura(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $this->say(...[
            '#menu_perfeziona', 'FIN-2026-0007', '#si', ...$this->personal(),
            'ACME Srl', '01/03/2015', 'media:D1:image/jpeg', 'media:D2:image/jpeg', 'salta', '#conferma',
        ]);

        $this->assertSame('completata', Conversation::first()->status);
        $this->assertSame([], Conversation::first()->data);
        $this->assertSame('Mario', LoanRequest::first()->personal['nome']);

        $this->say('#menu_richiedi', '#mutuo', 'annulla');
        $this->assertSame([], Conversation::latest('id')->first()->data);
    }
}
