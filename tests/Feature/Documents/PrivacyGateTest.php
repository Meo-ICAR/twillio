<?php

namespace Tests\Feature\Documents;

use App\Jobs\AnalyzeAttachment;
use App\Models\Attachment;
use App\Models\FinanziamentoDocument;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\DocumentPipeline;
use App\Services\Documents\DocumentReader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyGateTest extends TestCase
{
    use RefreshDatabase;

    public int $reads = 0;

    public ?array $reading = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        $this->seed(DocumentCatalogSeeder::class);
        $this->app->instance(DocumentReader::class, new class($this) implements DocumentReader
        {
            public function __construct(private PrivacyGateTest $test) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                $this->test->reads++;

                return $this->test->reading;
            }
        });
    }

    private function loan(bool $verified = false): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'informativa_ricevuta',
            'answers' => [], 'privacy_received_at' => now(), 'privacy_verified_at' => $verified ? now() : null,
        ]);
    }

    private function upload(LoanRequest $loan, string $code): Attachment
    {
        $slot = PraticaDocument::populate($loan)->firstWhere('code', $code);
        $slot->update(['status' => 'ricevuto']);
        Storage::disk('local')->put("pratiche/x/{$code}.jpg", 'BYTES');

        return Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => $code, 'path' => "pratiche/x/{$code}.jpg", 'mime' => 'image/jpeg', 'received_at' => now()]);
    }

    private function identity(): array
    {
        return ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035'];
    }

    private function informativa(bool $ours = true, bool $signed = true): array
    {
        return ['kind_detected' => 'informativa', 'legible' => true, 'matches_template' => $ours, 'signed' => $signed];
    }

    public function test_ogni_prodotto_prevede_l_informativa_firmata_come_primo_documento_obbligatorio(): void
    {
        foreach (FinanziamentoDocument::distinct()->pluck('product') as $product) {
            $first = FinanziamentoDocument::where('product', $product)->orderBy('sort_order')->first();

            $this->assertSame('informativa', $first->code, $product);
            $this->assertSame('obbligatorio', $first->requirement, $product);
            $this->assertSame('informativa', $first->ai_kind, $product);
        }
    }

    public function test_la_pratica_ha_il_documento_informativa(): void
    {
        $slots = PraticaDocument::populate($this->loan());

        $this->assertSame('informativa', $slots->first()->code);
        $this->assertSame('da_ricevere', $slots->first()->status);
    }

    public function test_un_documento_arrivato_prima_dell_informativa_verificata_aspetta_senza_essere_letto(): void
    {
        $loan = $this->loan(verified: false);
        $this->reading = $this->identity();
        $a = $this->upload($loan, 'documento_identita');

        $outcome = app(DocumentPipeline::class)->run($a, ['tipo_documento']);

        $this->assertSame('in_attesa', $outcome->status);
        $this->assertSame(0, $this->reads, 'nessun dato personale va all\'AI prima dell\'informativa verificata');
        $this->assertSame('in_attesa_informativa', $a->fresh()->status);
        $this->assertSame(['tipo_documento'], $a->fresh()->pending_checks);
        $this->assertSame('ricevuto', $a->praticaDocument->fresh()->status);
        $this->assertSame(0, $loan->fields()->count());
    }

    public function test_il_job_avvisa_l_agente_che_il_documento_aspetta_l_informativa(): void
    {
        $a = $this->upload($this->loan(), 'documento_identita');

        AnalyzeAttachment::dispatchSync($a->id);

        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], 'informativa') && str_contains($r['text']['body'], 'appena'));
    }

    public function test_l_informativa_si_legge_anche_prima_di_essere_verificata(): void
    {
        $loan = $this->loan(verified: false);
        $this->reading = $this->informativa();
        $a = $this->upload($loan, 'informativa');

        $outcome = app(DocumentPipeline::class)->run($a);

        $this->assertSame('verificato', $outcome->status);
        $this->assertSame(1, $this->reads);
        $this->assertSame('ok', $a->praticaDocument->fresh()->status);
    }

    public function test_l_informativa_a_posto_verifica_la_privacy_e_fa_partire_le_analisi_in_attesa(): void
    {
        $loan = $this->loan();
        $waiting = $this->upload($loan, 'documento_identita');
        $waiting->update(['status' => 'in_attesa_informativa', 'pending_checks' => ['tipo_documento']]);
        $analyzed = $this->upload($loan, 'reddito');
        $analyzed->update(['status' => 'verificato']);
        $this->reading = $this->informativa();
        $informativa = $this->upload($loan, 'informativa');

        Bus::fake();
        app()->call([new AnalyzeAttachment($informativa->id), 'handle']);

        $this->assertNotNull($loan->fresh()->privacy_verified_at);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class, fn (AnalyzeAttachment $job) => $job->attachmentId === $waiting->id && $job->checks === ['tipo_documento']);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class, 1);
    }

    public function test_un_informativa_non_conforme_non_verifica_la_privacy_e_i_documenti_restano_in_attesa(): void
    {
        $loan = $this->loan();
        $waiting = $this->upload($loan, 'documento_identita');
        $waiting->update(['status' => 'in_attesa_informativa']);
        $this->reading = $this->informativa(ours: true, signed: false);
        $informativa = $this->upload($loan, 'informativa');

        Bus::fake();
        app()->call([new AnalyzeAttachment($informativa->id), 'handle']);

        $this->assertNull($loan->fresh()->privacy_verified_at);
        $this->assertSame('rejected', $informativa->praticaDocument->fresh()->status);
        Bus::assertNotDispatched(AnalyzeAttachment::class);
        $this->assertSame('in_attesa_informativa', $waiting->fresh()->status);
    }

    public function test_l_operatore_che_approva_l_informativa_verifica_la_privacy(): void
    {
        $loan = $this->loan();
        $waiting = $this->upload($loan, 'documento_identita');
        $waiting->update(['status' => 'in_attesa_informativa']);
        $slot = $this->upload($loan, 'informativa')->praticaDocument;

        Bus::fake();
        $slot->approve(1);

        $this->assertNotNull($loan->fresh()->privacy_verified_at);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class, fn (AnalyzeAttachment $job) => $job->attachmentId === $waiting->id);
    }

    public function test_l_operatore_che_rifiuta_l_informativa_toglie_la_verifica(): void
    {
        $loan = $this->loan(verified: true);
        $slot = $this->upload($loan, 'informativa')->praticaDocument;

        $slot->reject('Firma mancante', 1);

        $this->assertNull($loan->fresh()->privacy_verified_at);
    }

    public function test_rifiutare_un_altro_documento_non_tocca_la_verifica(): void
    {
        $loan = $this->loan(verified: true);
        $slot = $this->upload($loan, 'documento_identita')->praticaDocument;

        $slot->reject('Illeggibile', 1);

        $this->assertNotNull($loan->fresh()->privacy_verified_at);
    }
}
