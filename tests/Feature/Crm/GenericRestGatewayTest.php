<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\GenericRestGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GenericRestGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0200', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36'],
        ]);
    }

    private function personal(): array
    {
        return ['cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/02/1980', 'residenza' => 'Via Roma 1, Milano', 'telefono' => '+393331234567', 'email' => 'mario@example.com'];
    }

    private function submit(array $config, ?LoanRequest $loan = null): int
    {
        $company = Company::create(['name' => 'H', 'crm_driver' => 'generic', 'crm_config' => $config + ['url' => 'https://crm.example.com/api/pratiche']]);

        return (new GenericRestGateway($company))->submit($loan ?? $this->loan(), $this->personal());
    }

    public function test_senza_modello_invia_l_intero_formato_unico_in_json(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 201)]);

        $this->assertSame(200, $this->submit([]));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST' && $request->url() === 'https://crm.example.com/api/pratiche'
                && $body['riferimento'] === 'FIN-2026-0200'
                && $body['cliente']['cognome'] === 'Rossi'
                && $body['cliente']['data_nascita'] === '1980-02-01'
                && $body['pratica']['durata_mesi'] === 36
                && $body['cliente']['residenza']['indirizzo'] === 'Via Roma 1, Milano'
                && $body['agente']['whatsapp'] === '393331112222'
                && ! array_key_exists('contenuto', $body);
        });
    }

    public function test_il_modello_sostituisce_i_segnaposto_e_conserva_i_tipi(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 200)]);

        $this->submit(['body_template' => json_encode([
            'lead' => ['cognome' => '{{cliente.cognome}}', 'rif' => 'WA-{{riferimento}}', 'mesi' => '{{pratica.durata_mesi}}', 'inesistente' => '{{cliente.boh}}'],
            'fisso' => 'x',
        ])]);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'lead' => ['cognome' => 'Rossi', 'rif' => 'WA-FIN-2026-0200', 'mesi' => 36, 'inesistente' => null],
            'fisso' => 'x',
        ]);
    }

    public function test_autenticazione_bearer_header_e_basic(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 200)]);

        $this->submit(['auth' => 'bearer', 'token' => 'T0KEN'], $this->loan());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer T0KEN'));

        Company::query()->delete();
        LoanRequest::query()->delete();
        $this->submit(['auth' => 'header', 'header_name' => 'X-Api-Key', 'token' => 'K'], $this->loan());
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', 'K'));

        Company::query()->delete();
        LoanRequest::query()->delete();
        $this->submit(['auth' => 'basic', 'username' => 'u', 'password' => 'p'], $this->loan());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('u:p')));
    }

    public function test_metodo_e_formato_form(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 200)]);

        $this->submit(['method' => 'put', 'format' => 'form', 'body_template' => '{"c":"{{cliente.cognome}}"}']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->isForm() && $r->data() === ['c' => 'Rossi']);
    }

    public function test_salva_l_id_della_pratica_nel_crm(): void
    {
        Http::fake(['crm.example.com/*' => Http::response(['data' => ['id' => 4711]], 201)]);
        $loan = $this->loan();

        $this->assertSame(200, $this->submit(['id_path' => 'data.id'], $loan));
        $this->assertSame('4711', $loan->fresh()->crm_lead_id);
    }

    public function test_un_errore_del_crm_restituisce_il_suo_codice_per_riprovare(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 500)]);

        $this->assertSame(500, $this->submit([]));
    }

    public function test_success_codes_restringe_gli_esiti_validi(): void
    {
        Http::fake(['crm.example.com/*' => Http::response([], 202)]);

        $this->assertSame(202, $this->submit(['success_codes' => '200,201']));
    }

    public function test_l_esito_si_puo_leggere_nel_corpo(): void
    {
        Http::fake(['crm.example.com/*' => Http::sequence()->push(['stato' => 'KO'], 200)->push(['stato' => 'OK'], 200)]);

        $this->assertSame(422, $this->submit(['ok_path' => 'stato', 'ok_value' => 'OK']));

        Company::query()->delete();
        LoanRequest::query()->delete();
        $this->assertSame(200, $this->submit(['ok_path' => 'stato', 'ok_value' => 'OK']));
    }

    public function test_crm_non_raggiungibile_vale_zero(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertSame(0, $this->submit([]));
    }

    public function test_senza_url_o_con_http_in_produzione_non_si_invia(): void
    {
        Http::fake();

        $this->assertSame(0, $this->submit(['url' => '']));

        Company::query()->delete();
        LoanRequest::query()->delete();
        $this->app['env'] = 'production';
        $this->assertSame(0, $this->submit(['url' => 'http://crm.example.com/api']));
        Http::assertNothingSent();
    }

    public function test_nel_log_non_finiscono_dati_personali(): void
    {
        Log::spy();
        Http::fake(['crm.example.com/*' => Http::response(['errore' => 'Mario Rossi non valido'], 400)]);

        $this->submit([]);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode($context), 'Rossi') && ! str_contains($message, 'Rossi'));
    }

    public function test_la_configurazione_e_cifrata_nel_database(): void
    {
        Company::create(['name' => 'H', 'crm_driver' => 'generic', 'crm_config' => ['url' => 'https://crm.example.com', 'token' => 'SEGRETO']]);

        $raw = \DB::table('companies')->value('crm_config');

        $this->assertStringNotContainsString('SEGRETO', $raw);
        $this->assertSame('SEGRETO', Company::first()->crm_config['token']);
    }
}
