<?php

namespace Tests\Feature\Core;

use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PdfModule;

/** Test che usano le tabelle del core: DB `unico_core_phpunit` con rollback; il resto sta su sqlite in memoria. */
abstract class CoreTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.core' => array_merge(config('database.connections.mysql'), ['database' => env('CORE_TEST_DB', 'unico_core_phpunit')]),
            'unico-core.connection' => 'core',
            'unico-core.signature.driver' => 'fake',
            'unico-core.pdf.pdftk_binary' => 'pdftk',
            'unico-core.documents_disk' => 'documents',
        ]);
        DB::purge('core');

        try {
            DB::connection('core')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('DB del core per i test non raggiungibile.');
        }

        Storage::fake('documents');
        Storage::fake(config('unico-core.pdf.modules_disk', 'public'));

        DB::connection('core')->beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::connection('core')->rollBack());
    }

    /** Una company del core (le chiavi esterne di tasks e documents ne vogliono una vera). */
    protected function coreCompany(string $name = 'GBD Energy'): \Unico\Core\Models\Company
    {
        return \Unico\Core\Models\Company::create(['name' => $name.' '.uniqid()]);
    }

    protected function fixturePdf(): string
    {
        return file_get_contents(base_path('tests/Fixtures/pdf/modulo-prova.pdf'));
    }

    /** Tipo documento + modulo PDF con la fixture; i riquadri di firma di default sono `cliente`. */
    protected function coreModule(?array $slots = null, string $name = 'Modulo prova'): PdfModule
    {
        $type = DocumentType::create(['name' => $name, 'code' => 'T'.uniqid(), 'is_monitored' => false]);
        $path = 'module/'.uniqid().'.pdf';
        Storage::disk(config('unico-core.pdf.modules_disk', 'public'))->put($path, $this->fixturePdf());

        return PdfModule::create([
            'name' => $name, 'file_path' => $path, 'document_type_id' => $type->id, 'is_active' => true,
            'signature_slots' => $slots ?? [['slot' => 'cliente', 'role' => 'client', 'page' => 1, 'x' => 50.0, 'y' => 700.0, 'width' => 150.0, 'height' => 40.0]],
        ]);
    }

    protected function makeLoan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'I1-'.uniqid(), 'agent_wa_number' => '393927968199', 'product' => 'luce', 'status' => 'richiesta',
            'answers' => ['consumo' => '3000'], 'personal' => [
                'codice_fiscale' => 'RSSMRA80A01H501U', 'cognome' => 'Rossi', 'nome' => 'Mario', 'residenza' => 'Via Roma 1, 00100 Roma',
                'telefono' => '3331234567', 'email' => 'mario@example.com', 'tipo_cliente' => 'privato',
            ],
        ]);
    }
}
