<?php

namespace Tests\Feature\Documents;

use App\Jobs\ArchiveLoanDocuments;
use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\LoggingSharePointUploader;
use App\Services\Documents\SharePointUploader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveLoanDocumentsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{0:string,1:string,2:string}> */
    public array $uploaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(array $override = []): LoanRequest
    {
        return LoanRequest::create($override + ['code' => 'FIN-2026-0007', 'agent_wa_number' => '39333', 'product' => 'personale', 'status' => 'perfezionata', 'answers' => []]);
    }

    private function attach(LoanRequest $loan, string $kind, string $name, string $body = 'PDF', bool $onDisk = true, ?string $slotStatus = null): Attachment
    {
        $path = "allegati/{$name}";
        $onDisk && Storage::disk('local')->put($path, $body);
        $slot = $slotStatus ? PraticaDocument::populate($loan)->first() : null;
        $slot?->update(['status' => $slotStatus]);

        return Attachment::create(['loan_request_id' => $loan->id, 'kind' => $kind, 'path' => $path, 'mime' => 'application/pdf', 'received_at' => now(), 'pratica_document_id' => $slot?->id]);
    }

    private function uploader(?\Throwable $fail = null): void
    {
        $this->app->instance(SharePointUploader::class, new class($this, $fail) implements SharePointUploader
        {
            public function __construct(private ArchiveLoanDocumentsTest $test, private ?\Throwable $fail) {}

            public function upload(string $folder, string $filename, string $contents): void
            {
                if ($this->fail) {
                    throw $this->fail;
                }
                $this->test->uploaded[] = [$folder, $filename, $contents];
            }
        });
    }

    private function archive(LoanRequest $loan): void
    {
        (new ArchiveLoanDocuments($loan->id))->handle(app(SharePointUploader::class));
    }

    public function test_carica_ogni_allegato_nella_cartella_della_pratica_e_registra_l_archiviazione(): void
    {
        $this->uploader();
        $loan = $this->loan();
        $a = $this->attach($loan, 'informativa', 'a.pdf', 'UNO');
        $b = $this->attach($loan, 'documento_identita', 'b.jpg', 'DUE');

        $this->archive($loan);

        $this->assertSame([['FIN-2026-0007', "informativa-{$a->id}.pdf", 'UNO'], ['FIN-2026-0007', "documento_identita-{$b->id}.jpg", 'DUE']], $this->uploaded);
        $this->assertNotNull($loan->fresh()->documents_archived_at);
    }

    public function test_salta_i_rifiutati_e_i_file_mancanti(): void
    {
        $this->uploader();
        $loan = $this->loan();
        $this->attach($loan, 'reddito', 'rifiutato.pdf', slotStatus: 'rejected');
        $this->attach($loan, 'codice_fiscale', 'manca.pdf', onDisk: false);
        $ok = $this->attach($loan, 'informativa', 'ok.pdf');

        $this->archive($loan);

        $this->assertCount(1, $this->uploaded);
        $this->assertSame("informativa-{$ok->id}.pdf", $this->uploaded[0][1]);
    }

    public function test_non_archivia_le_pratiche_di_prova(): void
    {
        $this->uploader();
        $loan = $this->loan(['is_test' => true]);
        $this->attach($loan, 'informativa', 'a.pdf');

        $this->archive($loan);

        $this->assertSame([], $this->uploaded);
        $this->assertNull($loan->fresh()->documents_archived_at);
    }

    public function test_se_il_caricamento_fallisce_l_eccezione_passa_e_non_si_registra_l_archiviazione(): void
    {
        $this->uploader(new \RuntimeException('sharepoint giù'));
        $loan = $this->loan();
        $this->attach($loan, 'informativa', 'a.pdf');

        try {
            $this->archive($loan);
            $this->fail('atteso RuntimeException');
        } catch (\RuntimeException) {
            $this->assertNull($loan->fresh()->documents_archived_at);
        }
    }

    public function test_il_caricatore_finto_scrive_nel_log_senza_contenuto(): void
    {
        Log::spy();

        (new LoggingSharePointUploader)->upload('FIN-2026-0007', 'informativa-1.pdf', 'DATI SEGRETI');

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context === ['folder' => 'FIN-2026-0007', 'file' => 'informativa-1.pdf', 'bytes' => 12]
            && ! str_contains(json_encode($context), 'SEGRETI'))->once();
        $this->assertInstanceOf(LoggingSharePointUploader::class, app(SharePointUploader::class));
    }
}
