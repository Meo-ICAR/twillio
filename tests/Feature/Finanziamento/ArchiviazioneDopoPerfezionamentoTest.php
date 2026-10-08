<?php

namespace Tests\Feature\Finanziamento;

use App\Jobs\ArchiveLoanDocuments;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Crm\CrmGateway;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Queue;

class ArchiviazioneDopoPerfezionamentoTest extends ConversationTestCase
{
    private function atSummary(): LoanRequest
    {
        Company::create(['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', '#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        return $loan;
    }

    private function gateway(int $result): void
    {
        $this->app->instance(CrmGateway::class, new class($result) implements CrmGateway
        {
            public function __construct(private int $result) {}

            public function submit(LoanRequest $loan, array $personal): int
            {
                return $this->result;
            }
        });
    }

    public function test_a_perfezionamento_riuscito_parte_l_archiviazione(): void
    {
        $loan = $this->atSummary();
        $this->gateway(200);
        Queue::fake();

        $this->say('#conferma');

        Queue::assertPushed(ArchiveLoanDocuments::class, fn ($job) => $job->loanId === $loan->id);
    }

    public function test_se_l_invio_fallisce_non_si_archivia(): void
    {
        $this->atSummary();
        $this->gateway(500);
        Queue::fake();

        $this->say('#conferma');

        Queue::assertNothingPushed();
    }

    public function test_un_errore_nell_avvio_dell_archiviazione_non_blocca_il_perfezionamento(): void
    {
        $loan = $this->atSummary();
        $this->gateway(200);
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('coda giù'))->getMock()->shouldIgnoreMissing());

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame('perfezionata', $loan->fresh()->status);
    }
}
