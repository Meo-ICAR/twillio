<?php

namespace Tests\Feature\Crm;

use App\Jobs\SendDocumentsToCrm;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Crm\Capabilities\SendsDocuments;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\CrmRegistry;
use App\Services\Crm\UnicoloanGateway;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnicoloanGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://unicoloan.example.com';

    private function company(array $config = []): Company
    {
        return Company::create(['name' => 'H', 'crm_driver' => 'unicoloan', 'crm_config' => $config + ['url' => self::BASE, 'token' => 'TOK', 'secret' => 'SEG']]);
    }

    private function loan(array $override = []): LoanRequest
    {
        return LoanRequest::create($override + [
            'code' => 'FIN-2026-0300', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'perfezionata',
            'answers' => ['prodotto' => 'personale', 'durata' => 'm36'], 'perfected_at' => now(),
        ]);
    }

    private function personal(array $override = []): array
    {
        return $override + ['cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U', 'data_nascita' => '01/02/1980', 'residenza' => 'Via Roma 1, Milano', 'telefono' => '+393331234567', 'email' => 'mario@example.com'];
    }

    private function gateway(?Company $company = null): UnicoloanGateway
    {
        return new UnicoloanGateway($company ?? $this->company());
    }

    private function agent(): void
    {
        \App\Models\Fornitore::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Agente', 'piva' => '12345678901', 'tel' => '+39 333 111 2222', 'is_active' => true, 'company_id' => (Company::first() ?? $this->company())->id]);
    }

    private function attach(LoanRequest $loan, string $kind, string $body = '%PDF-1.4 contenuto', ?string $slotStatus = null): Attachment
    {
        $path = "pratiche/{$loan->code}/{$kind}-".uniqid().'.pdf';
        Storage::disk('local')->put($path, $body);
        $slot = PraticaDocument::where('loan_request_id', $loan->id)->where('code', $kind)->first();
        $slot?->update(['status' => $slotStatus ?? 'ricevuto']);

        return Attachment::create(['loan_request_id' => $loan->id, 'kind' => $kind, 'path' => $path, 'mime' => 'application/pdf', 'status' => 'verificato', 'received_at' => now(), 'pratica_document_id' => $slot?->id, 'analysis' => ['fields' => ['nome' => 'Mario'], 'discrepancies' => ['data_scaduta']]]);
    }

    public function test_e_nel_registro_con_la_capacita_di_consegnare_i_documenti(): void
    {
        $registry = app(CrmRegistry::class);

        $this->assertTrue($registry->has('unicoloan'));
        $this->assertTrue($registry->supports('unicoloan', SendsDocuments::class));
        $this->assertInstanceOf(UnicoloanGateway::class, $registry->make('unicoloan', $this->company()));
    }

    public function test_la_richiesta_e_firmata_e_porta_cliente_pratica_e_agente(): void
    {
        $this->agent();
        Http::fake(['unicoloan.example.com/*' => Http::response(['codice_pratica' => 'WA-FIN-2026-0300'], 201)]);

        $this->assertSame(200, $this->gateway()->submit($loan = $this->loan(), $this->personal()));
        $this->assertSame('WA-FIN-2026-0300', $loan->fresh()->crm_lead_id);

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $expected = hash_hmac('sha256', implode('.', [$request->header('X-Timestamp')[0], 'POST', '/api/agente/v1/richieste', hash('sha256', $body)]), 'SEG');

            return $request->url() === self::BASE.'/api/agente/v1/richieste'
                && $request->hasHeader('Authorization', 'Bearer TOK')
                && hash_equals($expected, $request->header('X-Signature')[0])
                && abs(time() - (int) $request->header('X-Timestamp')[0]) < 5
                && $request['riferimento'] === 'FIN-2026-0300'
                && $request['cliente']['codice_fiscale'] === 'RSSMRA80A01H501U'
                && $request['agente']['partita_iva'] === '12345678901'
                && $request['pratica']['durata_mesi'] === 36;
        });
    }

    public function test_gli_errori_di_unicoloan_si_riportano_per_riprovare(): void
    {
        $this->agent();
        Http::fake(['unicoloan.example.com/*' => Http::sequence()->push(['codice' => 'cliente_di_altro_agente'], 409)->push([], 500)]);

        $this->assertSame(409, $this->gateway()->submit($loan = $this->loan(), $this->personal()));
        $this->assertSame(500, $this->gateway()->submit($loan, $this->personal()));
        $this->assertNull($loan->fresh()->crm_lead_id);
    }

    public function test_senza_codice_fiscale_o_agente_non_si_invia_nulla(): void
    {
        Http::fake();

        $this->agent();
        $this->assertSame(422, $this->gateway()->submit($this->loan(), $this->personal(['codice_fiscale' => ''])));
        Http::assertNothingSent();
    }

    public function test_servizio_irraggiungibile_vale_zero(): void
    {
        $this->agent();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertSame(0, $this->gateway()->submit($this->loan(), $this->personal()));
    }

    public function test_con_la_configurazione_incompleta_non_si_invia_nulla(): void
    {
        $this->agent();
        Http::fake();
        $incomplete = Company::create(['name' => 'X', 'crm_driver' => 'unicoloan', 'crm_config' => ['url' => self::BASE]]);
        $insecure = Company::create(['name' => 'Y', 'crm_driver' => 'unicoloan', 'crm_config' => ['url' => 'http://unicoloan.example.com', 'token' => 't', 'secret' => 's']]);
        $this->app['env'] = 'production';

        foreach ([$incomplete, $insecure] as $company) {
            $this->assertSame(0, (new UnicoloanGateway($company))->submit($this->loan(['code' => 'FIN-'.$company->id]), $this->personal()));
            $this->assertSame(0, (new UnicoloanGateway($company))->sendDocuments($this->loan(['code' => 'DOC-'.$company->id])));
        }
        Http::assertNothingSent();
    }

    public function test_i_documenti_vanno_uno_per_uno_firmati_con_l_hash_del_file(): void
    {
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);
        $a = $this->attach($loan, 'documento_identita', '%PDF-1.4 identita');
        $this->attach($loan, 'codice_fiscale', '%PDF-1.4 cf');
        Http::fake(['unicoloan.example.com/*' => Http::response(['id' => 1], 201)]);

        $this->assertSame(200, $this->gateway()->sendDocuments($loan));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($a) {
            $hash = hash('sha256', '%PDF-1.4 identita');
            $parts = collect($request->data())->keyBy('name');

            return $request->url() === self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/documenti'
                && $request->header('X-Content-Sha256')[0] === $hash
                && $request->header('X-Signature')[0] === hash_hmac('sha256', implode('.', [$request->header('X-Timestamp')[0], 'POST', '/api/agente/v1/richieste/FIN-2026-0300/documenti', $hash]), 'SEG')
                && ($parts['tipo']['contents'] ?? null) === 'documento_identita'
                && ($parts['id_origine']['contents'] ?? null) === (string) $a->id
                && json_decode($parts['analisi']['contents'], true) === ['esito' => 'verificato', 'tipo' => 'documento_identita', 'discrepanze' => ['data_scaduta']];
        });
        $this->assertSame(2, $loan->attachments()->whereNotNull('crm_sent_at')->count());
    }

    public function test_i_dati_letti_dal_documento_si_mandano_solo_se_richiesto(): void
    {
        Storage::fake('local');
        $loan = $this->loan();
        $this->attach($loan, 'documento_identita');
        Http::fake(['unicoloan.example.com/*' => Http::response([], 201)]);

        $this->gateway($this->company(['analysis' => 'full']))->sendDocuments($loan);

        Http::assertSent(fn (Request $request) => json_decode(collect($request->data())->keyBy('name')['analisi']['contents'], true)['campi'] === ['nome' => 'Mario']);
    }

    public function test_si_riprende_da_dove_si_era_fermi_senza_rimandare_i_file(): void
    {
        Storage::fake('local');
        $loan = $this->loan();
        $this->attach($loan, 'documento_identita', '%PDF-1.4 uno');
        $this->attach($loan, 'codice_fiscale', '%PDF-1.4 due');
        Http::fake(['unicoloan.example.com/*' => Http::sequence()->push([], 201)->push([], 503)->push([], 201)]);

        $this->assertSame(503, $this->gateway()->sendDocuments($loan));
        $this->assertSame(1, $loan->attachments()->whereNotNull('crm_sent_at')->count());

        $this->assertSame(200, $this->gateway()->sendDocuments($loan));
        $this->assertSame(0, $loan->attachments()->whereNull('crm_sent_at')->count());
        Http::assertSentCount(3);

        $this->assertSame(200, $this->gateway()->sendDocuments($loan));
        Http::assertSentCount(3);
    }

    public function test_i_documenti_rifiutati_o_senza_file_si_saltano(): void
    {
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);
        $this->attach($loan, 'documento_identita', '%PDF-1.4 x', 'rejected');
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'codice_fiscale', 'path' => 'pratiche/inesistente.pdf', 'mime' => 'application/pdf', 'received_at' => now()]);
        Http::fake();

        $this->assertSame(200, $this->gateway()->sendDocuments($loan));
        Http::assertNothingSent();
    }

    public function test_il_job_ritenta_se_la_consegna_non_riesce(): void
    {
        Storage::fake('local');
        $this->company();
        $loan = $this->loan();
        $this->attach($loan, 'documento_identita');
        Http::fake(['unicoloan.example.com/*' => Http::response([], 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('codice 500');

        (new SendDocumentsToCrm($loan->id))->handle(app(CrmRegistry::class));
    }

    public function test_il_job_non_fa_nulla_per_crm_che_non_accettano_documenti_o_pratiche_di_prova(): void
    {
        Company::create(['name' => 'A', 'url_istruttoria' => 'https://crm.example.com']);
        $loan = $this->loan();
        Http::fake();

        (new SendDocumentsToCrm($loan->id))->handle(app(CrmRegistry::class));
        Http::assertNothingSent();

        $test = $this->loan(['code' => 'FIN-T', 'is_test' => true]);
        Company::query()->update(['crm_driver' => 'unicoloan', 'crm_config' => ['url' => self::BASE, 'token' => 't', 'secret' => 's']]);
        (new SendDocumentsToCrm($test->id))->handle(app(CrmRegistry::class));
        Http::assertNothingSent();
    }

    public function test_il_comando_rimette_in_coda_solo_le_pratiche_perfezionate_con_file_da_inviare(): void
    {
        Bus::fake();
        $this->company();
        $withPending = $this->loan();
        $this->attach($withPending, 'documento_identita');
        $sent = $this->loan(['code' => 'FIN-S']);
        $this->attach($sent, 'documento_identita')->update(['crm_sent_at' => now()]);
        $notPerfected = $this->loan(['code' => 'FIN-N', 'perfected_at' => null, 'status' => 'richiesta']);
        $this->attach($notPerfected, 'documento_identita');

        $this->artisan('crm:send-documents')->assertSuccessful();

        Bus::assertDispatchedTimes(SendDocumentsToCrm::class, 1);
        Bus::assertDispatched(SendDocumentsToCrm::class, fn ($job) => $job->loanId === $withPending->id);
    }

    public function test_il_gateway_dell_applicazione_usa_unicoloan_quando_l_azienda_lo_sceglie(): void
    {
        $this->agent();
        $this->company();
        config(['finanziamento.crm.driver' => 'production']);
        Http::fake(['unicoloan.example.com/*' => Http::response(['codice_pratica' => 'WA-X'], 201)]);

        $this->assertSame(200, app(CrmGateway::class)->submit($this->loan(), $this->personal()));
        Http::assertSentCount(1);
    }

    public function test_dichiara_le_capacita_documentali(): void
    {
        $registry = app(CrmRegistry::class);

        foreach ([\App\Services\Crm\Capabilities\ProvidesTemplates::class, \App\Services\Crm\Capabilities\FillsForms::class, \App\Services\Crm\Capabilities\RequestsSignature::class] as $capability) {
            $this->assertTrue($registry->supports('unicoloan', $capability));
        }
    }

    public function test_elenca_e_scarica_i_template(): void
    {
        Http::fake([
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/moduli' => Http::response(['moduli' => [['id' => 7, 'nome' => 'QAV']]]),
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/moduli/7/template' => Http::response('%PDF-vuoto'),
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/moduli/9/template' => Http::response(['codice' => 'modulo_non_trovato'], 404),
        ]);
        $gateway = $this->gateway();
        $loan = $this->loan();

        $this->assertSame([['code' => '7', 'name' => 'QAV']], $gateway->templates($loan));
        $this->assertSame('%PDF-vuoto', $gateway->downloadTemplate($loan, '7'));
        $this->assertNull($gateway->downloadTemplate($loan, '9'));
    }

    public function test_il_modulo_compilato_si_scarica_dopo_averlo_creato(): void
    {
        Http::fake([
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/moduli/7/compilato' => Http::response(['id' => 'doc-1'], 201),
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/documenti/doc-1/file' => Http::response('%PDF-compilato'),
        ]);

        $this->assertSame('%PDF-compilato', $this->gateway()->fillForm($this->loan(), '7'));
    }

    public function test_la_firma_compila_il_modulo_la_richiede_e_se_ne_legge_lo_stato(): void
    {
        Http::fake([
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/moduli/7/compilato' => Http::response(['id' => 'doc-1'], 201),
            self::BASE.'/api/agente/v1/richieste/FIN-2026-0300/documenti/doc-1/firma' => Http::sequence()
                ->push(['stato' => 'sent'], 201)->push(['stato' => 'signed'])->push(['stato' => 'declined']),
        ]);
        $gateway = $this->gateway();
        $loan = $this->loan();

        $this->assertSame(['status' => 'inviata', 'reference' => 'doc-1'], $gateway->requestSignature($loan, '7'));
        $this->assertSame(['status' => 'firmata', 'reference' => 'doc-1'], $gateway->signatureStatus($loan, 'doc-1'));
        $this->assertSame('rifiutata', $gateway->signatureStatus($loan, 'doc-1')['status']);
    }

    public function test_gli_errori_delle_funzioni_documentali_non_lanciano(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $gateway = $this->gateway();
        $loan = $this->loan();

        $this->assertSame([], $gateway->templates($loan));
        $this->assertNull($gateway->fillForm($loan, '7'));
        $this->assertSame(['status' => 'errore', 'reference' => null], $gateway->requestSignature($loan, '7'));
        $this->assertSame('errore', $gateway->signatureStatus($loan, 'doc-1')['status']);
    }
}
