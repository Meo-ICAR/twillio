<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaField;
use App\Services\Documents\DocumentReader;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\Http;

/** Perfezionamento GBD: documenti letti dall'AI, domande solo per ciò che manca, provenienza 🟢/🟡, pagamento e terzo intestatario. */
class GbdPerfezionamentoDocumentiTest extends ConversationTestCase
{
    protected string $agent = '393927968199';

    /** @var array<string,array<string,mixed>> lettura per tipo di documento */
    public array $readings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GbdEnergySeeder::class);
        config(['unico-core.openapi.token' => null]);
        $this->readings = [
            'bolletta' => ['kind_detected' => 'bolletta', 'legible' => true, 'pod' => 'IT001E12345678', 'pdr' => null, 'supply_address' => 'Via Verdi 2, 00100 Roma', 'committed_power' => '3', 'previous_supplier' => 'Enel Energia'],
            'documento_identita' => ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035', 'document_type' => 'patente'],
            'visura' => ['kind_detected' => 'visura', 'legible' => true, 'company_name' => 'ACME Srl', 'vat_number' => '12345678903', 'registered_office' => 'Via Po 2, 10100 Torino'],
            'estratto_conto' => ['kind_detected' => 'estratto_conto', 'legible' => true, 'iban' => 'IT60X0542811101000000123456', 'account_holder' => 'Mario Rossi'],
        ];
        $this->app->instance(DocumentReader::class, new class($this) implements DocumentReader
        {
            public function __construct(private GbdPerfezionamentoDocumentiTest $test) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return $this->test->readings[$kind] ?? null;
            }
        });
    }

    private function node(): string
    {
        return Conversation::where('status', 'attiva')->latest('id')->firstOrFail()->node;
    }

    private function afterResponse(): void
    {
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    /** Preventivo e avvio del perfezionamento con l'informativa già verificata. */
    private function start(array $quote = ['#privato', '#luce', '#principale', '80']): string
    {
        $this->say('ciao', '#menu_richiedi', ...[...$quote, '#conferma']);
        $code = LoanRequest::latest('id')->value('code');
        LoanRequest::where('code', $code)->update(['privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si');

        return $code;
    }

    public function test_la_bolletta_letta_evita_le_domande_su_fornitura_e_si_vede_nel_riepilogo(): void
    {
        $code = $this->start();

        $this->say('media:B1:image/jpeg');
        $this->say('salta', 'salta');
        $this->afterResponse();
        $this->say('avanti');
        $this->assertSame('rivedi_dati', $this->node());
        $this->say('#conferma');

        // Dalla bolletta: indirizzo di fornitura, POD, potenza e fornitore attuale non si chiedono più.
        $this->assertSame('codice_fiscale', $this->node());
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma');
        $this->assertSame('pagamento', $this->node());
        $this->say('#bollettino', '#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');

        $summary = $this->summaryText();
        $this->assertStringContainsString('🟢 POD: IT001E12345678', $summary);
        $this->assertStringContainsString('🟡 Codice fiscale', $summary);
        $this->assertStringContainsString('letto dai documenti', $summary);
        $this->assertSame('IT001E12345678', PraticaField::confirmedValues(LoanRequest::where('code', $code)->first())['pod']);
    }

    private function summaryText(): string
    {
        $engine = app(\App\Services\Conversation\ConversationEngine::class);
        $replies = $engine->handle(new \App\Services\Conversation\IncomingMessage($this->agent, 'text', 'ciao'));

        return $this->bodies($replies);
    }

    public function test_tutti_i_documenti_saltati_portano_alle_domande_a_mano_e_al_riepilogo_con_soli_gialli(): void
    {
        $this->start();
        $this->say('salta', 'salta', 'salta');

        $this->assertSame('codice_fiscale', $this->node());
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#bollettino', '#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');

        $summary = $this->summaryText();
        $this->assertStringContainsString('🟡', $summary);
        $this->assertStringNotContainsString('🟢 Codice', $summary);
        $this->assertSame('riepilogo', $this->node());
    }

    public function test_azienda_con_visura_non_chiede_destinazione_d_uso_e_compila_ragione_sociale(): void
    {
        $code = $this->start(['#azienda', '#luce', '#bt', '350']);

        $this->say('salta', 'salta');
        $this->assertSame('doc_visura', $this->node());
        $this->say('media:V1:application/pdf');
        $this->say('salta');
        $this->afterResponse();
        $this->say('avanti', '#conferma');

        $data = Conversation::where('status', 'attiva')->latest('id')->first()->data;
        $this->assertSame('ACME Srl', $data['ragione_sociale']);
        $this->assertSame('Via Po 2, 10100 Torino', $data['residenza']);
    }

    public function test_iban_letto_con_intestatario_diverso_segna_il_terzo_intestatario(): void
    {
        $this->readings['estratto_conto']['account_holder'] = 'Luigi Bianchi';
        $code = $this->start();

        $this->say('salta', 'salta');
        $this->say('media:E1:image/jpeg');
        $this->afterResponse();
        $this->say('avanti', '#conferma');
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#sdd');
        $this->assertSame('titolo_possesso', $this->node(), 'IBAN e intestatario letti: non si chiedono');
        $this->say('#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');
        $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('si', $loan->personal['terzo_intestatario']);
        $this->assertSame('IT60X0542811101000000123456', $loan->personal['iban']);
    }

    public function test_pagamento_con_bollettino_non_chiede_l_iban(): void
    {
        $this->start();
        $this->say('salta', 'salta', 'salta', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#bollettino');

        $this->assertSame('titolo_possesso', $this->node());
    }

    public function test_conto_non_del_richiedente_segna_il_terzo_intestatario(): void
    {
        $code = $this->start();
        $this->say('salta', 'salta', 'salta', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#sdd', 'IT60X0542811101000000123456', '#no', 'Luigi Bianchi');
        $this->assertSame('titolo_possesso', $this->node());
        $this->say('#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');
        $this->say('#conferma');

        $this->assertSame('si', LoanRequest::where('code', $code)->first()->personal['terzo_intestatario']);
    }

    public function test_il_catasto_parte_in_background_per_i_privati_e_non_per_le_aziende(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\FetchCadastreData::class]);
        $this->start();
        $this->say('salta', 'salta', 'salta', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '3', 'Enel Energia', '#bollettino', '#proprieta', '#residenza', '3331234567', 'mario@example.com', '#si');
        $this->say('#conferma');
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\FetchCadastreData::class);

        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\FetchCadastreData::class]);
        Conversation::query()->delete();
        $this->start(['#azienda', '#luce', '#bt', '350']);
        $this->say('salta', 'salta', 'salta', 'salta', '12345678903', 'ACME Srl', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', '15', 'Enel Energia', '#bollettino', '#proprieta', '3331234567', 'info@acme.example', '#si');
        $this->say('#conferma');
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\FetchCadastreData::class);
    }
}
