<?php

namespace Tests\Feature\Finanziamento;

use App\Mail\LoanSubmissionMail;
use App\Models\Company;
use App\Models\LoanRequest;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\Mail;

class GbdEnergyFlowTest extends ConversationTestCase
{
    protected string $agent = '393927968199';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GbdEnergySeeder::class);
    }

    private function gbdId(): int
    {
        return Company::where('name', 'GBD Energy')->value('id');
    }

    public function test_installatore_1_fa_la_simulazione_e_riceve_l_offerta_migliore(): void
    {
        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#privato', '#luce', '#principale', '80', '#conferma'));

        $this->assertStringContainsString('Offerta consigliata', $text);
        $loan = LoanRequest::latest('id')->first();
        $this->assertSame('luce', $loan->product);
        $this->assertSame($this->gbdId(), $loan->company_id);
    }

    public function test_azienda_in_bassa_tensione_ha_il_prezzo_senza_iva(): void
    {
        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#azienda', '#luce', '#bt', '350', '#conferma'));

        $this->assertStringContainsString('IVA e imposte escluse', $text);
        $this->assertStringContainsString('Edison Placet Business', $text);
        $this->assertStringNotContainsString('consulenza energetica', $text);
    }

    public function test_azienda_in_media_tensione_ha_un_preventivo_indicativo(): void
    {
        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#azienda', '#luce', '#mt', '5000', '#conferma'));

        $this->assertStringContainsString('Edison Business MT', $text);
        $this->assertStringContainsString('consulenza energetica', $text);
    }

    public function test_luce_e_gas_danno_due_offerte_e_chiedono_pod_e_pdr(): void
    {
        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#privato', '#luce_gas', '#seconda', '150', '#conferma'));

        $this->assertStringContainsString('Luce:', $text);
        $this->assertStringContainsString('Gas:', $text);
        $this->assertStringContainsString('Edison Sweet', $text);

        $code = LoanRequest::latest('id')->value('code');
        $this->assertSame('luce_gas', LoanRequest::latest('id')->value('product'));
        LoanRequest::where('code', $code)->update(['privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si', 'salta', 'salta', 'salta', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si');
        $this->assertSame('pod', $this->node());
        $this->say('IT001E12345678');
        $this->assertSame('pdr', $this->node());
        $this->say('12345678901234');
        $this->assertSame('potenza', $this->node());
    }

    public function test_senza_prodotti_adatti_rimanda_al_customer_care(): void
    {
        Company::find($this->gbdId())->products()->detach();

        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#privato', '#gas', '#principale', '90', '#conferma'));

        $this->assertStringContainsString('customer care di GBD Energy', $text);
    }

    public function test_i_percorsi_di_gbd_non_hanno_errori_di_validazione(): void
    {
        foreach (\App\Models\Flow::where('company_id', $this->gbdId())->get() as $flow) {
            $this->assertSame([], app(\App\Services\Flows\FlowValidator::class)->flowErrors($flow), $flow->code);
        }
        $this->assertSame(2, \App\Models\Flow::where('company_id', $this->gbdId())->count());
    }

    public function test_il_menu_e_quello_di_gbd(): void
    {
        $options = collect($this->say('ciao'))->flatMap(fn ($r) => $r->options)->all();

        $this->assertContains('Nuova offerta', $options);
        $this->assertNotContains('Modifica Preventivo', $options);
    }

    /** Preventivo e avvio del perfezionamento, con l'informativa già verificata; si ferma alla domanda sul primo documento. */
    private function startPerfection(bool $azienda = false): string
    {
        config(['unico-core.openapi.token' => null]);
        $this->say('ciao', '#menu_richiedi', ...[...($azienda ? ['#azienda', '#luce', '#bt', '350'] : ['#privato', '#luce', '#principale', '80']), '#conferma']);
        $code = LoanRequest::latest('id')->value('code');
        LoanRequest::where('code', $code)->update(['privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si');

        return $code;
    }

    /** Salta tutti i caricamenti: il bot passa alle domande a mano. */
    private function skipDocuments(bool $azienda = false): void
    {
        $this->say(...array_fill(0, $azienda ? 4 : 3, 'salta'));
    }

    private function node(): ?string
    {
        return \App\Models\Conversation::where('status', 'attiva')->latest('id')->value('node');
    }

    private function cfBorn(\Illuminate\Support\Carbon $date): string
    {
        return 'RSSMRA'.$date->format('y').'ABCDEHLMPRST'[$date->month - 1].$date->format('d').'H501U';
    }

    public function test_dopo_la_conferma_della_pratica_chiede_il_primo_documento_senza_chiedere_il_tipo_di_cliente(): void
    {
        $this->startPerfection();

        $this->assertSame('doc_bolletta', $this->node());
    }

    public function test_il_perfezionamento_di_un_privato_chiude_con_la_mail_all_istruttoria(): void
    {
        $code = $this->startPerfection();
        $this->skipDocuments();

        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#bollettino', '#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');
        $replies = $this->say('#conferma');

        $this->assertSame('perfezionata', LoanRequest::where('code', $code)->value('status'), $this->bodies($replies));
        $this->assertSame('privato', LoanRequest::where('code', $code)->first()->personal['tipo_cliente']);
        Mail::assertSent(LoanSubmissionMail::class, fn ($mail) => $mail->hasTo('contratti@gbdenergy.example'));
    }

    public function test_se_l_indirizzo_di_fornitura_e_diverso_lo_chiede(): void
    {
        $code = $this->startPerfection();
        $this->skipDocuments();

        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#no');
        $this->assertSame('indirizzo_fornitura', $this->node());
        $this->say('Via Verdi 2, 00100 Roma', 'IT001E12345678', '3', 'Enel Energia', '#bollettino', '#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');
        $this->say('#conferma');

        $this->assertSame('Via Verdi 2, 00100 Roma', LoanRequest::where('code', $code)->first()->personal['indirizzo_fornitura']);
    }

    public function test_se_l_indirizzo_di_fornitura_e_lo_stesso_non_lo_chiede(): void
    {
        $code = $this->startPerfection();
        $this->skipDocuments();

        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si');

        $this->assertSame('pod', $this->node());
    }

    private function toSupplyPoint(string $fornitura): string
    {
        $this->say('ciao', '#menu_richiedi', '#privato', '#'.$fornitura, '#principale', '80', '#conferma');
        $code = LoanRequest::latest('id')->value('code');
        LoanRequest::where('code', $code)->update(['privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si');
        $this->skipDocuments();
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si');

        return $code;
    }

    public function test_per_la_luce_chiede_solo_il_pod(): void
    {
        $this->toSupplyPoint('luce');
        $this->assertSame('pod', $this->node());

        $text = $this->bodies($this->say('12345678901234'));
        $this->assertStringContainsString('POD non valido', $text);

        $this->say('IT001E12345678');
        $this->assertSame('potenza', $this->node());
    }

    public function test_per_il_gas_chiede_solo_il_pdr(): void
    {
        $this->toSupplyPoint('gas');
        $this->assertSame('pdr', $this->node());

        $text = $this->bodies($this->say('IT001E12345678'));
        $this->assertStringContainsString('PDR non valido', $text);

        $this->say('12345678901234');
        $this->assertSame('potenza', $this->node());
    }

    private function toCurrentSupplier(): void
    {
        $this->toSupplyPoint('luce');
        $this->say('IT001E12345678', '3');
        $this->assertSame('fornitore_attuale', $this->node());
    }

    /** Dal fornitore attuale alle domande sul cellulare: pagamento con bollettino, titolo di possesso e destinazione d'uso. */
    private function toPhone(): void
    {
        $this->say('#bollettino', '#proprieta', '#residenza');
    }

    public function test_un_fornitore_attuale_ambiguo_chiede_di_precisare_e_propone_i_candidati(): void
    {
        $this->toCurrentSupplier();

        $text = $this->bodies($this->say('Enel'));

        $this->assertStringContainsString('ENEL ENERGIA S.P.A.', $text);
        $this->assertSame('fornitore_attuale', $this->node());
    }

    public function test_un_fornitore_sconosciuto_si_richiede(): void
    {
        $this->toCurrentSupplier();

        $text = $this->bodies($this->say('Zzxqwv Energie'));

        $this->assertStringContainsString('altro', $text);
        $this->assertSame('fornitore_attuale', $this->node());
    }

    public function test_un_fornitore_riconosciuto_salva_ragione_sociale_e_partita_iva_ufficiali(): void
    {
        $this->toCurrentSupplier();

        $this->say('enel energia');

        $this->assertSame('pagamento', $this->node());
        $data = \App\Models\Conversation::where('status', 'attiva')->latest('id')->first()->data;
        $this->assertSame('ENEL ENERGIA S.P.A.', $data['fornitore_attuale_nome']);
        $this->assertSame('06655971007', $data['fornitore_attuale_piva']);
    }

    public function test_altro_permette_di_proseguire_per_un_fornitore_non_in_elenco(): void
    {
        $this->toCurrentSupplier();

        $this->say('altro');

        $this->assertSame('pagamento', $this->node());
    }

    public function test_un_indirizzo_email_non_valido_si_richiede_con_il_motivo(): void
    {
        $this->toCurrentSupplier();
        $this->say('enel energia');
        $this->toPhone();
        $this->say('3331234567');
        $this->assertSame('email', $this->node());

        $text = $this->bodies($this->say('mario@example'));

        $this->assertStringContainsString('suffisso', $text);
        $this->assertSame('email', $this->node());
    }

    public function test_un_telefono_fisso_o_con_lettere_si_richiede(): void
    {
        $this->toCurrentSupplier();
        $this->say('enel energia');
        $this->toPhone();
        $this->assertSame('telefono', $this->node());

        $this->assertStringContainsString('cellulare', $this->bodies($this->say('0612345678')));
        $this->assertSame('telefono', $this->node());
        $this->assertStringContainsString('Numero non valido', $this->bodies($this->say('333abc4567')));
        $this->assertSame('telefono', $this->node());
    }

    public function test_il_perfezionamento_di_un_azienda_chiede_partita_iva_e_ragione_sociale(): void
    {
        $code = $this->startPerfection(true);
        $this->skipDocuments(true);

        $this->say('12345678903', 'ACME Srl', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '15', 'Enel Energia');
        $this->say('#bollettino', '#proprieta');
        $this->assertSame('telefono', $this->node(), 'per un\'azienda non si chiede la destinazione d\'uso');
        $this->say('3331234567', 'info@acme.example', '#si');
        $replies = $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertContains($loan->status, ['perfezionata', 'invio_istruttoria'], $this->bodies($replies));
        $this->assertSame('ACME Srl', $loan->personal['ragione_sociale']);
        $this->assertSame('azienda', $loan->personal['tipo_cliente']);
        $this->assertArrayNotHasKey('destinazione_uso', $loan->personal);
    }

    public function test_una_partita_iva_non_valida_si_richiede(): void
    {
        $this->startPerfection(true);
        $this->skipDocuments(true);

        $text = $this->bodies($this->say('12345678904'));

        $this->assertStringContainsString('Partita IVA non valida', $text);
        $this->assertSame('partita_iva', $this->node());
    }

    public function test_un_codice_fiscale_non_valido_si_richiede(): void
    {
        $this->startPerfection();
        $this->skipDocuments();

        $text = $this->bodies($this->say('ABCDEF'));

        $this->assertStringContainsString('Codice fiscale non valido', $text);
        $this->assertSame('codice_fiscale', $this->node());
    }

    public function test_un_minorenne_viene_rifiutato(): void
    {
        $this->startPerfection();
        $this->skipDocuments();

        $text = $this->bodies($this->say($this->cfBorn(now()->subYears(18)->addDay())));

        $this->assertStringContainsString('minorenne', $text);
        $this->assertSame('codice_fiscale', $this->node());
    }

    public function test_cognome_e_nome_non_coerenti_col_codice_fiscale_chiedono_conferma(): void
    {
        $this->startPerfection();
        $this->skipDocuments();

        $text = $this->bodies($this->say('RSSMRA80A01H501U', 'Bianchi', 'Luigi'));

        $this->assertStringContainsString('Confermi il codice fiscale', $text);
        $this->assertSame('conferma_cf', $this->node());
    }
}
