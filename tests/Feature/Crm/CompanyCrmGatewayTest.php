<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\Capabilities\FillsForms;
use App\Services\Crm\Capabilities\RequestsSignature;
use App\Services\Crm\Capabilities\SendsDocuments;
use App\Services\Crm\CompanyCrmGateway;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\CrmRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanyCrmGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function loan(): LoanRequest
    {
        return LoanRequest::create(['code' => 'FIN-2026-0100', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
    }

    private function gateway(): CrmGateway
    {
        config(['finanziamento.crm.driver' => 'production']);

        return app(CrmGateway::class);
    }

    public function test_senza_scelta_un_url_istruttoria_vuol_dire_mediafacile_come_prima(): void
    {
        $this->assertSame('mediafacile', Company::create(['name' => 'A', 'url_istruttoria' => 'https://crm.example.com/ws'])->crmDriver());
        $this->assertNull(Company::create(['name' => 'B'])->crmDriver());
    }

    public function test_la_scelta_esplicita_prevale_e_email_vuol_dire_nessun_crm(): void
    {
        $this->assertSame('generic', Company::create(['name' => 'A', 'crm_driver' => 'generic', 'url_istruttoria' => 'https://crm.example.com/ws'])->crmDriver());

        $email = Company::create(['name' => 'B', 'crm_driver' => 'email', 'url_istruttoria' => 'https://crm.example.com/ws']);
        $this->assertNull($email->crmDriver());
        $this->assertFalse($email->hasSubmissionCrm());
    }

    public function test_un_driver_generico_senza_url_url_istruttoria_conta_come_crm(): void
    {
        $this->assertTrue(Company::create(['name' => 'A', 'crm_driver' => 'generic', 'crm_config' => ['url' => 'https://x.example.com']])->hasSubmissionCrm());
    }

    public function test_il_driver_scelto_dall_azienda_riceve_la_pratica(): void
    {
        Company::create(['name' => 'A', 'crm_driver' => 'generic', 'crm_config' => ['url' => 'https://crm.example.com/api/pratiche']]);
        Http::fake(['crm.example.com/*' => Http::response(['ok' => true], 201)]);

        $this->assertSame(200, $this->gateway()->submit($this->loan(), ['cognome' => 'Rossi']));
        Http::assertSentCount(1);
    }

    public function test_senza_driver_non_si_invia_nulla(): void
    {
        Company::create(['name' => 'A']);
        Http::fake();

        $this->assertSame(0, $this->gateway()->submit($this->loan(), []));
        Http::assertNothingSent();
    }

    public function test_un_driver_sconosciuto_non_manda_in_errore_la_conversazione(): void
    {
        Company::create(['name' => 'A', 'crm_driver' => 'inesistente']);

        $this->assertSame(0, $this->gateway()->submit($this->loan(), []));
    }

    public function test_la_simulazione_vale_per_tutte_le_aziende(): void
    {
        Company::create(['name' => 'A']);
        config(['finanziamento.crm.driver' => 'simulated', 'finanziamento.crm.simulated_status' => 503]);

        $this->assertSame(503, app(CrmGateway::class)->submit($this->loan(), []));
    }

    public function test_un_nuovo_crm_si_aggiunge_registrando_una_classe(): void
    {
        $registry = app(CrmRegistry::class);
        $registry->register('prova', FakeCrm::class, 'CRM di prova');

        Company::create(['name' => 'A', 'crm_driver' => 'prova']);

        $this->assertSame('CRM di prova', $registry->labels()['prova']);
        $this->assertSame(204, $this->gateway()->submit($this->loan(), []));
    }

    public function test_le_funzioni_oltre_l_invio_sono_capacita_opzionali_del_driver(): void
    {
        $registry = app(CrmRegistry::class);
        $registry->register('completo', FakeFullCrm::class);

        $this->assertTrue($registry->supports('completo', SendsDocuments::class));
        $this->assertTrue($registry->supports('completo', FillsForms::class));
        $this->assertTrue($registry->supports('completo', RequestsSignature::class));
        $this->assertFalse($registry->supports('mediafacile', SendsDocuments::class));
        $this->assertFalse($registry->supports('inesistente', SendsDocuments::class));
    }
}

class FakeCrm implements CrmGateway
{
    public function __construct(public Company $company) {}

    public function submit(LoanRequest $loan, array $personal): int
    {
        return 204;
    }
}

class FakeFullCrm extends FakeCrm implements FillsForms, RequestsSignature, SendsDocuments
{
    public function sendDocuments(LoanRequest $loan): int
    {
        return 200;
    }

    public function fillForm(LoanRequest $loan, string $template): ?string
    {
        return '%PDF';
    }

    public function requestSignature(LoanRequest $loan, string $document): array
    {
        return ['status' => 'inviata', 'reference' => 'r1'];
    }

    public function signatureStatus(LoanRequest $loan, string $reference): array
    {
        return ['status' => 'firmata', 'reference' => $reference];
    }
}
