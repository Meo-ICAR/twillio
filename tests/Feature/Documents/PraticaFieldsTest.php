<?php

namespace Tests\Feature\Documents;

use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\PraticaField;
use App\Services\Documents\DocumentPipeline;
use App\Services\Documents\DocumentReader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PraticaFieldsTest extends TestCase
{
    use RefreshDatabase;

    public ?array $reading = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
        $this->app->instance(DocumentReader::class, new class($this) implements DocumentReader
        {
            public function __construct(private PraticaFieldsTest $test) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return $this->test->reading;
            }
        });
    }

    private function loan(array $personal = []): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'informativa_ricevuta',
            'answers' => [], 'personal' => $personal, 'privacy_received_at' => now(), 'privacy_verified_at' => now(),
        ]);
    }

    private function upload(LoanRequest $loan, string $code): Attachment
    {
        $slot = PraticaDocument::populate($loan)->firstWhere('code', $code);
        Storage::disk('local')->put("pratiche/x/{$code}.jpg", 'BYTES');

        return Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => $code, 'path' => "pratiche/x/{$code}.jpg", 'mime' => 'image/jpeg', 'received_at' => now()]);
    }

    private function identity(array $override = []): array
    {
        return $override + ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035'];
    }

    private function values(LoanRequest $loan, string $status = 'proposto'): array
    {
        return $loan->fields()->where('status', $status)->orderBy('key')->get()->pluck('value', 'key')->all();
    }

    public function test_si_propone_un_valore_con_la_sua_fonte_e_si_conserva_cifrato(): void
    {
        $loan = $this->loan();
        $a = $this->upload($loan, 'documento_identita');

        PraticaField::propose($loan, ['cognome' => 'ROSSI'], $a);

        $field = $loan->fields()->first();
        $this->assertSame('cognome', $field->key);
        $this->assertSame('ROSSI', $field->value);
        $this->assertSame('proposto', $field->status);
        $this->assertSame('documento_identita', $field->source_code);
        $this->assertSame($a->id, $field->attachment_id);
        $this->assertStringNotContainsString('ROSSI', (string) DB::table('pratica_fields')->value('value'), 'il dato personale non è in chiaro sul database');
    }

    public function test_una_nuova_proposta_sostituisce_quella_non_ancora_confermata_ma_non_un_dato_confermato(): void
    {
        $loan = $this->loan();
        $a = $this->upload($loan, 'documento_identita');
        PraticaField::propose($loan, ['cognome' => 'ROSI', 'nome' => 'MARIO'], $a);
        PraticaField::propose($loan, ['cognome' => 'ROSSI'], $a);
        $this->assertSame(['cognome' => 'ROSSI', 'nome' => 'MARIO'], $this->values($loan));

        $loan->fields()->where('key', 'nome')->first()->confirm();
        PraticaField::propose($loan, ['nome' => 'LUIGI'], $a);

        $this->assertSame(['nome' => 'MARIO'], $this->values($loan, 'confermato'));
        $this->assertSame(2, $loan->fields()->count(), 'una riga per dato');
    }

    public function test_si_conferma_con_un_valore_corretto_dall_agente_o_si_rifiuta(): void
    {
        $loan = $this->loan();
        $a = $this->upload($loan, 'documento_identita');
        PraticaField::propose($loan, ['cognome' => 'ROSI', 'nome' => 'MARIO'], $a);

        $loan->fields()->where('key', 'cognome')->first()->confirm('ROSSI');
        $loan->fields()->where('key', 'nome')->first()->reject();

        $this->assertSame(['cognome' => 'ROSSI'], $this->values($loan, 'confermato'));
        $this->assertSame(['nome' => 'MARIO'], $this->values($loan, 'rifiutato'));
        $this->assertNotNull($loan->fields()->where('key', 'cognome')->first()->confirmed_at);
        $this->assertSame(['cognome' => 'ROSSI'], PraticaField::confirmedValues($loan));
    }

    public function test_un_documento_a_posto_lascia_i_dati_letti_come_proposte(): void
    {
        $loan = $this->loan();
        $this->reading = $this->identity();

        app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->assertSame([
            'codice_fiscale' => 'RSSMRA80A01H501U', 'cognome' => 'ROSSI', 'documento_numero' => 'AB123456',
            'documento_scadenza' => '01/01/2035', 'nome' => 'MARIO',
        ], $this->values($loan));
        $this->assertSame(['documento_identita'], $loan->fields()->pluck('source_code')->unique()->values()->all());
    }

    public function test_un_documento_con_problemi_non_lascia_proposte(): void
    {
        $loan = $this->loan(['cognome' => 'Bianchi']);
        $this->reading = $this->identity();

        $outcome = app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->assertSame('difforme', $outcome->status);
        $this->assertSame(0, $loan->fields()->count());
    }

    public function test_il_secondo_documento_si_confronta_con_i_dati_gia_letti_dal_primo(): void
    {
        $loan = $this->loan();
        $this->reading = $this->identity();
        app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->reading = ['kind_detected' => 'codice_fiscale', 'legible' => true, 'surname' => 'VERDI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U'];
        $outcome = app(DocumentPipeline::class)->run($this->upload($loan, 'codice_fiscale'));

        $this->assertSame('difforme', $outcome->status);
        $this->assertStringContainsString('Cognome', implode(' ', $outcome->issues));
        $this->assertSame('ROSSI', $loan->fields()->where('key', 'cognome')->value('value'), 'il dato del primo documento non cambia');
    }

    public function test_si_ricarica_lo_stesso_documento_corretto_senza_scontrarsi_con_la_lettura_precedente(): void
    {
        $loan = $this->loan();
        $this->reading = $this->identity(['surname' => 'ROSI']);
        app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->reading = $this->identity();
        $outcome = app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->assertSame('verificato', $outcome->status);
        $this->assertSame('ROSSI', $loan->fields()->where('key', 'cognome')->value('value'), 'la proposta è quella del documento nuovo');
    }

    public function test_i_dati_dichiarati_dall_agente_hanno_la_precedenza_sui_campi_letti(): void
    {
        $loan = $this->loan(['cognome' => 'Rossi']);
        PraticaField::propose($loan, ['cognome' => 'VERDI'], $this->upload($loan, 'codice_fiscale'));
        $this->reading = $this->identity();

        $outcome = app(DocumentPipeline::class)->run($this->upload($loan, 'documento_identita'));

        $this->assertSame('verificato', $outcome->status, 'si confronta con ciò che ha dichiarato l\'agente');
    }

    public function test_i_campi_sono_cancellati_con_la_pratica(): void
    {
        $loan = $this->loan();
        PraticaField::propose($loan, ['cognome' => 'ROSSI'], $this->upload($loan, 'documento_identita'));

        $loan->delete();

        $this->assertSame(0, PraticaField::count());
    }
}
