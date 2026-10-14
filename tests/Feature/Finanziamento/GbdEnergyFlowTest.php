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
        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma'));

        $this->assertStringContainsString('Offerta consigliata', $text);
        $loan = LoanRequest::latest('id')->first();
        $this->assertSame('luce', $loan->product);
        $this->assertSame($this->gbdId(), $loan->company_id);
    }

    public function test_senza_prodotti_adatti_rimanda_al_customer_care(): void
    {
        Company::find($this->gbdId())->products()->detach();

        $text = $this->bodies($this->say('ciao', '#menu_richiedi', '#gas', '1.200', '0', '#conferma'));

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

    private function startPerfection(): string
    {
        $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $code = LoanRequest::latest('id')->value('code');
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si');

        return $code;
    }

    private function node(): ?string
    {
        return \App\Models\Conversation::where('status', 'attiva')->latest('id')->value('node');
    }

    private function cfBorn(\Illuminate\Support\Carbon $date): string
    {
        return 'RSSMRA'.$date->format('y').'ABCDEHLMPRST'[$date->month - 1].$date->format('d').'H501U';
    }

    public function test_dopo_la_conferma_della_pratica_chiede_se_privato_o_azienda(): void
    {
        $this->startPerfection();

        $this->assertSame('tipo_cliente', $this->node());
    }

    public function test_il_perfezionamento_di_un_privato_chiude_con_la_mail_all_istruttoria(): void
    {
        $code = $this->startPerfection();

        $this->say('#privato', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', 'Enel', '3331234567', 'mario@example.com', '#si');
        $replies = $this->say('#conferma');

        $this->assertSame('perfezionata', LoanRequest::where('code', $code)->value('status'), $this->bodies($replies));
        Mail::assertSent(LoanSubmissionMail::class, fn ($mail) => $mail->hasTo('contratti@gbdenergy.example'));
    }

    public function test_se_l_indirizzo_di_fornitura_e_diverso_lo_chiede(): void
    {
        $code = $this->startPerfection();

        $this->say('#privato', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#no');
        $this->assertSame('indirizzo_fornitura', $this->node());
        $this->say('Via Verdi 2, 00100 Roma', 'IT001E12345678', 'Enel', '3331234567', 'mario@example.com', '#si');
        $this->say('#conferma');

        $this->assertSame('Via Verdi 2, 00100 Roma', LoanRequest::where('code', $code)->first()->personal['indirizzo_fornitura']);
    }

    public function test_se_l_indirizzo_di_fornitura_e_lo_stesso_non_lo_chiede(): void
    {
        $code = $this->startPerfection();

        $this->say('#privato', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si');

        $this->assertSame('pod_pdr', $this->node());
    }

    public function test_il_perfezionamento_di_un_azienda_chiede_ragione_sociale_e_partita_iva(): void
    {
        $code = $this->startPerfection();

        $this->say('#azienda', 'ACME Srl', '12345678903', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', 'Enel', '3331234567', 'info@acme.example', '#si');
        $replies = $this->say('#conferma');

        $this->assertSame('perfezionata', LoanRequest::where('code', $code)->value('status'), $this->bodies($replies));
        $this->assertSame('ACME Srl', LoanRequest::where('code', $code)->first()->personal['ragione_sociale']);
    }

    public function test_una_partita_iva_non_valida_si_richiede(): void
    {
        $this->startPerfection();

        $text = $this->bodies($this->say('#azienda', 'ACME Srl', '12345678904'));

        $this->assertStringContainsString('Partita IVA non valida', $text);
        $this->assertSame('partita_iva', $this->node());
    }

    public function test_un_codice_fiscale_non_valido_si_richiede(): void
    {
        $this->startPerfection();

        $text = $this->bodies($this->say('#privato', 'ABCDEF'));

        $this->assertStringContainsString('Codice fiscale non valido', $text);
        $this->assertSame('codice_fiscale', $this->node());
    }

    public function test_un_minorenne_viene_rifiutato(): void
    {
        $this->startPerfection();

        $text = $this->bodies($this->say('#privato', $this->cfBorn(now()->subYears(18)->addDay())));

        $this->assertStringContainsString('minorenne', $text);
        $this->assertSame('codice_fiscale', $this->node());
    }

    public function test_cognome_e_nome_non_coerenti_col_codice_fiscale_chiedono_conferma(): void
    {
        $this->startPerfection();

        $text = $this->bodies($this->say('#privato', 'RSSMRA80A01H501U', 'Bianchi', 'Luigi'));

        $this->assertStringContainsString('Confermi il codice fiscale', $text);
        $this->assertSame('conferma_cf', $this->node());
    }
}
