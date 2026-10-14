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

    public function test_il_perfezionamento_chiude_con_la_mail_all_istruttoria(): void
    {
        $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $code = LoanRequest::latest('id')->value('code');

        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si');
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', 'Via Verdi 2, 00100 Roma', 'IT001E12345678', 'Enel', '3331234567', 'mario@example.com', '#si');
        $replies = $this->say('#conferma');

        $this->assertSame('perfezionata', LoanRequest::where('code', $code)->value('status'), $this->bodies($replies));
        Mail::assertSent(LoanSubmissionMail::class, fn ($mail) => $mail->hasTo('contratti@gbdenergy.example'));
    }
}
