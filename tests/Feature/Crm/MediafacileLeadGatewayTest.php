<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\CompanyCrmGateway;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\MediafacileLeadGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileLeadGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0007', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'lavoro' => 'dip_pub'],
        ]);
    }

    private function personal(): array
    {
        return ['cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'residenza' => 'Via Roma 1, Milano', 'telefono' => '+393331234567', 'email' => 'mario@example.com'];
    }

    private function company(array $override = []): Company
    {
        return Company::create($override + ['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead', 'istruttoria_passkey' => 'KEY']);
    }

    private function submit(?LoanRequest $loan = null): int
    {
        return (new MediafacileLeadGateway)->submit($loan ?? $this->loan(), $this->personal());
    }

    public function test_il_gateway_dell_applicazione_sceglie_il_driver_dall_azienda(): void
    {
        $this->assertInstanceOf(CompanyCrmGateway::class, app(CrmGateway::class));

        // Con la simulazione risponde la simulazione, qualunque sia l'azienda.
        $loan = $this->loan();
        $this->assertSame(200, app(CrmGateway::class)->submit($loan, $this->personal()));

        // In produzione un'azienda con l'URL dell'istruttoria usa Mediafacile, come prima.
        config(['finanziamento.crm.driver' => 'mediafacile']);
        $this->company();
        Http::fake(['crm.example.com/*' => Http::response('<r><Stato>OK</Stato><IDUU>77</IDUU></r>', 200)]);

        $this->assertSame(200, app(CrmGateway::class)->submit($loan, $this->personal()));
        $this->assertSame('77', $loan->fresh()->crm_lead_id);
    }

    public function test_ok_da_200_e_salva_l_id_del_lead(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::response('<Risposta><Stato>OK</Stato><IDUU>LEAD-77</IDUU></Risposta>')]);
        $loan = $this->loan();

        $this->assertSame(200, $this->submit($loan));
        $this->assertSame('LEAD-77', $loan->fresh()->crm_lead_id);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET'
                && str_starts_with($r->url(), 'https://crm.example.com/ws/lead?')
                && $q['Passkey'] === 'KEY' && $q['cognome'] === 'Rossi' && $q['fonte'] === 'unicoagent'
                && ! array_key_exists('file', $q);
        });
    }

    public function test_ko_non_e_un_successo_e_non_salva_l_id(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::response('<Risposta><Stato>KO - email non valida</Stato><IDUU>LEAD-78</IDUU></Risposta>')]);
        $loan = $this->loan();

        $this->assertNotSame(200, $this->submit($loan));
        $this->assertNull($loan->fresh()->crm_lead_id);
    }

    public function test_xml_non_valido_e_http_500_non_sono_successi(): void
    {
        $this->company();
        $loan = $this->loan();
        Http::fake(['crm.example.com/*' => Http::sequence()->push('non è xml')->push('boom', 500)]);
        $this->assertNotSame(200, $this->submit($loan));
        $this->assertNull($loan->fresh()->crm_lead_id);
    }

    public function test_un_timeout_non_e_un_successo(): void
    {
        $this->company();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertNotSame(200, $this->submit());
    }

    public function test_senza_url_o_passkey_non_si_chiama_il_servizio(): void
    {
        Http::fake();
        $this->company(['istruttoria_passkey' => null]);

        $this->assertNotSame(200, $this->submit());
        Http::assertNothingSent();
    }
}
