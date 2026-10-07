<?php

namespace Tests\Feature\Documents;

use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\DocumentPipeline;
use App\Services\Documents\DocumentReader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function useReader(?array $fields): void
    {
        $this->app->instance(DocumentReader::class, new class($fields) implements DocumentReader
        {
            public function __construct(private ?array $fields) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return $this->fields;
            }
        });
    }

    private function attachment(string $code = 'documento_identita', array $personal = ['cognome' => 'Rossi', 'nome' => 'Mario']): array
    {
        $loan = LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'informativa_ricevuta', 'answers' => [], 'personal' => $personal]);
        $slot = PraticaDocument::populate($loan)->firstWhere('code', $code);
        Storage::disk('local')->put("pratiche/x/{$code}.jpg", 'BYTES');
        $a = Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => $code, 'path' => "pratiche/x/{$code}.jpg", 'mime' => 'image/jpeg', 'received_at' => now()]);

        return [$a, $slot];
    }

    private function identity(array $override = []): array
    {
        return $override + ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035'];
    }

    public function test_usa_i_controlli_predefiniti_del_tipo_di_documento(): void
    {
        $this->useReader($this->identity(['surname' => 'BIANCHI']));
        [$a, $slot] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a);

        $this->assertSame('difforme', $outcome->status);
        $this->assertStringContainsString('BIANCHI', $outcome->issues[0]);
        $this->assertSame('rejected', $slot->fresh()->status);
    }

    public function test_i_controlli_agganciati_al_passo_sostituiscono_quelli_predefiniti(): void
    {
        $this->useReader($this->identity(['surname' => 'BIANCHI'])); // il cognome non coincide, ma si chiede solo il tipo
        [$a, $slot] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a, ['tipo_documento']);

        $this->assertSame('verificato', $outcome->status);
        $this->assertSame('ok', $slot->fresh()->status);
    }

    public function test_i_controlli_agganciati_possono_avere_parametri(): void
    {
        $this->useReader($this->identity());
        [$a] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a, [['name' => 'tipo_documento']]);

        $this->assertSame('verificato', $outcome->status);
    }

    public function test_il_primo_controllo_che_fallisce_ferma_gli_altri(): void
    {
        $this->useReader(['kind_detected' => 'reddito', 'legible' => true, 'surname' => 'BIANCHI']);
        [$a] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a);

        $this->assertCount(1, $outcome->issues, 'solo il tipo sbagliato, non anche i nomi');
        $this->assertStringContainsString('documento di reddito', $outcome->issues[0]);
    }

    public function test_un_controllo_sconosciuto_o_di_un_altro_tipo_viene_saltato_e_registrato_senza_contenuti(): void
    {
        $this->useReader($this->identity());
        [$a] = $this->attachment();
        Log::spy();

        $outcome = app(DocumentPipeline::class)->run($a, ['non_esiste', 'iban', 'tipo_documento']);

        $this->assertSame('verificato', $outcome->status);
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_senza_lettura_il_documento_resta_non_analizzato(): void
    {
        $this->useReader(null);
        [$a, $slot] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a);

        $this->assertSame('non_analizzato', $outcome->status);
        $this->assertSame('non_analizzato', $a->fresh()->status);
        $this->assertNotSame('ok', $slot->fresh()->status);
    }

    public function test_i_dati_proposti_dai_controlli_sono_restituiti(): void
    {
        $this->useReader($this->identity());
        [$a] = $this->attachment();

        $outcome = app(DocumentPipeline::class)->run($a, ['tipo_documento', 'estrai_dati']);

        $this->assertSame('ROSSI', $outcome->proposals['cognome']);
        $this->assertSame('AB123456', $outcome->proposals['documento_numero']);
    }

    public function test_un_documento_senza_tipo_di_lettura_non_si_analizza(): void
    {
        $this->useReader($this->identity());
        [$a] = $this->attachment('estratto_conto');

        $this->assertNull(app(DocumentPipeline::class)->run($a));
    }
}
