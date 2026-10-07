<?php

namespace Tests\Feature\Finanziamento;

use App\Mail\LoanSubmissionMail;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\SimulatedCrmGateway;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvioCrmTest extends ConversationTestCase
{
    /** @var array<int,array<string,mixed>> */
    public array $sent = [];

    private function loanAtSummary(): LoanRequest
    {
        Company::create(['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/pratiche']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        return $loan;
    }

    private function gateway(int|\Throwable $result): void
    {
        $this->app->instance(CrmGateway::class, new class($this, $result) implements CrmGateway
        {
            public function __construct(private InvioCrmTest $test, private int|\Throwable $result) {}

            public function submit(LoanRequest $loan, array $personal): int
            {
                $this->test->sent[] = ['code' => $loan->code, 'personal' => $personal];
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    public function test_la_simulazione_risponde_200_di_default_e_si_configura(): void
    {
        $this->assertInstanceOf(SimulatedCrmGateway::class, app(CrmGateway::class));
        $this->assertSame(200, app(CrmGateway::class)->submit(new LoanRequest, []));

        config(['finanziamento.crm.simulated_status' => 503]);
        $this->assertSame(503, app(CrmGateway::class)->submit(new LoanRequest, []));
    }

    public function test_con_200_la_pratica_e_inviata_in_istruttoria(): void
    {
        $loan = $this->loanAtSummary();
        $this->gateway(200);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame('perfezionata', $loan->fresh()->status);
        $this->assertCount(1, $this->sent);
        $this->assertSame('Mario', $this->sent[0]['personal']['nome']);
        $this->assertSame('completata', Conversation::first()->status);
    }

    public function test_con_una_risposta_diversa_da_200_non_si_perfeziona_e_si_puo_riprovare(): void
    {
        $loan = $this->loanAtSummary();
        $this->gateway(500);

        $replies = $this->say('#conferma');

        $this->assertStringContainsString('Invio pratica fallito, riprovare o contattare Istruttoria', $this->bodies($replies));
        $this->assertSame('riepilogo_p', Conversation::first()->node);
        $this->assertSame('attiva', Conversation::first()->status);
        $this->assertSame('Mario', Conversation::first()->data['nome'], 'i dati non si perdono');
        $this->assertNotSame('perfezionata', $loan->fresh()->status);
        $this->assertNull($loan->fresh()->personal);
        $this->assertSame('conferma', array_key_first(end($replies)->options), 'torna il riepilogo con il pulsante di invio');

        $this->gateway(200);
        $this->assertStringContainsString('inviata in istruttoria', $this->bodies($this->say('#conferma')));
        $this->assertSame('perfezionata', $loan->fresh()->status);
    }

    public function test_un_errore_del_crm_vale_come_invio_fallito_e_non_finisce_nel_log_con_i_dati(): void
    {
        $loan = $this->loanAtSummary();
        $this->gateway(new \RuntimeException('timeout con Mario Rossi'));
        Log::spy();

        $this->assertStringContainsString('Invio pratica fallito', $this->bodies($this->say('#conferma')));

        $this->assertNotSame('perfezionata', $loan->fresh()->status);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => ! str_contains(json_encode([$m, $c]), 'Mario'))->once();
    }

    public function test_senza_crm_per_l_istruttoria_la_pratica_va_per_email(): void
    {
        Company::query()->delete();
        $this->gateway(500);
        $loan = $this->loanAtSummary();
        Company::query()->update(['url_istruttoria' => null]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame([], $this->sent, 'il CRM non è chiamato');
        Mail::assertSent(LoanSubmissionMail::class);
        $this->assertSame('perfezionata', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->emailed_at);
    }

    public function test_se_la_mail_non_parte_la_pratica_non_si_perfeziona(): void
    {
        $loan = $this->loanAtSummary();
        Company::query()->update(['url_istruttoria' => null]);
        config(['finanziamento.mail.to' => null]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('Invio pratica fallito', $body);
        $this->assertSame('informativa_ricevuta', $loan->fresh()->status);
        $this->assertNull($loan->fresh()->personal);
        $this->assertNull($loan->fresh()->perfected_at);
        $this->assertSame('attiva', Conversation::first()->status);
    }
}
