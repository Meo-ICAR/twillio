<?php

namespace Tests\Feature\Documents;

use App\Jobs\AnalyzeAttachment;
use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\DocumentReader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzeAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
    }

    /** @param array<string,mixed>|null|\Throwable $result */
    private function useReader(mixed $result, bool $enabled = true): void
    {
        $this->app->instance(DocumentReader::class, new class($result, $enabled) implements DocumentReader
        {
            public function __construct(private mixed $result, private bool $enabled) {}

            public function enabled(): bool
            {
                return $this->enabled;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    private function attachment(string $kind = 'documento_identita'): Attachment
    {
        $loan = LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'informativa_ricevuta',
            'answers' => ['prodotto' => 'personale'],
            'personal' => ['cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U', 'data_nascita' => '01/01/1980'],
        ]);
        $path = "pratiche/FIN-2026-0001/{$kind}-x.jpg";
        Storage::disk('local')->put($path, 'BYTES');

        return Attachment::create(['loan_request_id' => $loan->id, 'kind' => $kind, 'path' => $path, 'mime' => 'image/jpeg', 'received_at' => now()]);
    }

    private function fields(array $override = []): array
    {
        return $override + ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'birth_date' => '01/01/1980'];
    }

    private function sentText(): string
    {
        $text = '';
        Http::assertSent(function (Request $r) use (&$text) {
            $text .= ($r['text']['body'] ?? '')."\n";

            return $r['to'] === '393331112222';
        });

        return $text;
    }

    public function test_documento_coerente_e_verificato_e_l_agente_riceve_l_esito(): void
    {
        $this->useReader($this->fields());
        $a = $this->attachment();

        AnalyzeAttachment::dispatchSync($a->id);

        $a->refresh();
        $this->assertSame('verificato', $a->status);
        $this->assertSame([], $a->analysis['discrepancies']);
        $this->assertSame('ROSSI', $a->analysis['fields']['surname']);
        $this->assertStringContainsString('nessuna differenza', $this->sentText());
        $this->assertStringNotContainsString('ROSSI', DB::table('attachments')->value('analysis'));
    }

    public function test_difformita_vengono_salvate_e_comunicate(): void
    {
        $this->useReader($this->fields(['surname' => 'BIANCHI']));
        $a = $this->attachment();

        AnalyzeAttachment::dispatchSync($a->id);

        $a->refresh();
        $this->assertSame('difforme', $a->status);
        $this->assertCount(1, $a->analysis['discrepancies']);
        $text = $this->sentText();
        $this->assertStringContainsString('Cognome', $text);
        $this->assertStringContainsString('BIANCHI', $text);
        $this->assertStringContainsString('Stato Pratiche', $text);
    }

    public function test_documento_illeggibile(): void
    {
        $this->useReader(['legible' => false, 'kind_detected' => 'identita']);
        $a = $this->attachment();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('non_leggibile', $a->fresh()->status);
        $this->assertStringContainsString('non è leggibile', $this->sentText());
    }

    public function test_se_la_lettura_non_riesce_il_file_resta_ricevuto_e_l_agente_lo_sa(): void
    {
        $this->useReader(null);
        $a = $this->attachment();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('non_analizzato', $a->fresh()->status);
        $this->assertStringContainsString('comunque ricevuto', $this->sentText());
    }

    public function test_un_errore_dell_api_non_blocca_e_non_scrive_dati_nel_log(): void
    {
        $this->useReader(new \RuntimeException('boom con dati Mario Rossi'));
        $a = $this->attachment();
        Log::spy();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('non_analizzato', $a->fresh()->status);
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return ! str_contains(json_encode([$message, $context]), 'Mario');
        })->once();
    }

    public function test_senza_chiave_ai_non_fa_nulla(): void
    {
        $this->useReader($this->fields(), enabled: false);
        $a = $this->attachment();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('ricevuto', $a->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_l_informativa_e_gli_allegati_spariti_vengono_ignorati(): void
    {
        $this->useReader($this->fields());
        $a = $this->attachment('informativa');

        AnalyzeAttachment::dispatchSync($a->id);
        AnalyzeAttachment::dispatchSync(99999);

        $this->assertSame('ricevuto', $a->fresh()->status);
        Http::assertNothingSent();
    }

    private function withSlot(string $code = 'documento_identita'): array
    {
        $this->seed(DocumentCatalogSeeder::class);
        $a = $this->attachment($code);
        PraticaDocument::populate($a->loanRequest);
        $slot = $a->loanRequest->praticaDocuments()->where('code', $code)->first();
        $slot->update(['status' => 'ricevuto']);
        $a->update(['pratica_document_id' => $slot->id]);

        return [$a, $slot];
    }

    public function test_documento_coerente_porta_il_documento_della_pratica_a_ok(): void
    {
        $this->useReader($this->fields());
        [$a, $slot] = $this->withSlot();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('ok', $slot->fresh()->status);
        $this->assertNotNull($slot->fresh()->reviewed_at);
        $this->assertSame('ai', $slot->fresh()->annotations[0]['by']);
    }

    public function test_difformita_portano_il_documento_a_rejected_con_le_annotazioni_dell_ai(): void
    {
        $this->useReader($this->fields(['surname' => 'BIANCHI', 'name' => 'LUCA']));
        [$a, $slot] = $this->withSlot();

        AnalyzeAttachment::dispatchSync($a->id);

        $slot->refresh();
        $this->assertSame('rejected', $slot->status);
        $this->assertCount(2, array_filter($slot->annotations, fn ($n) => $n['by'] === 'ai'));
        $this->assertStringContainsString('BIANCHI', $slot->annotations[0]['text']);
    }

    public function test_illeggibile_porta_a_rejected(): void
    {
        $this->useReader(['legible' => false, 'kind_detected' => 'identita']);
        [$a, $slot] = $this->withSlot();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('rejected', $slot->fresh()->status);
    }

    public function test_se_la_lettura_non_riesce_il_documento_resta_ricevuto_per_l_istruttore(): void
    {
        $this->useReader(null);
        [$a, $slot] = $this->withSlot();

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('ricevuto', $slot->fresh()->status);
        $this->assertNull($slot->fresh()->annotations);
    }

    public function test_i_documenti_senza_lettura_ai_restano_ricevuti_e_l_api_non_viene_chiamata(): void
    {
        $this->useReader(new \RuntimeException('non deve essere chiamata'));
        [$a, $slot] = $this->withSlot('estratto_conto');

        AnalyzeAttachment::dispatchSync($a->id);

        $this->assertSame('ricevuto', $slot->fresh()->status);
        $this->assertSame('ricevuto', $a->fresh()->status);
        Http::assertNothingSent();
    }
}
