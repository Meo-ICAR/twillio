<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeUnperfectedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function loan(string $code, string $status, int $daysOld): LoanRequest
    {
        return LoanRequest::create([
            'code' => $code, 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => $status,
            'answers' => ['prodotto' => 'personale'], 'created_at' => now()->subDays($daysOld),
        ]);
    }

    private function withFile(LoanRequest $loan): string
    {
        $path = "pratiche/{$loan->code}/informativa-x.pdf";
        Storage::disk('local')->put($path, 'PDF');
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'informativa', 'path' => $path, 'mime' => 'application/pdf', 'received_at' => now()]);

        return $path;
    }

    public function test_cancella_le_pratiche_non_perfezionate_oltre_il_termine_con_i_loro_file(): void
    {
        $old = $this->loan('FIN-2026-0001', 'informativa_ricevuta', 31);
        $path = $this->withFile($old);
        $this->loan('FIN-2026-0002', 'richiesta', 40);
        $this->loan('FIN-2026-0003', 'in_attesa_informativa', 100);

        $this->artisan('finanziamento:purge')->assertSuccessful();

        $this->assertSame(0, LoanRequest::count());
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_non_tocca_le_pratiche_perfezionate_ne_quelle_recenti(): void
    {
        $this->loan('FIN-2026-0001', 'perfezionata', 400);
        $this->loan('FIN-2026-0002', 'richiesta', 29);
        $this->loan('FIN-2026-0003', 'informativa_ricevuta', 0);
        $path = $this->withFile(LoanRequest::where('code', 'FIN-2026-0001')->first());

        $this->artisan('finanziamento:purge')->assertSuccessful();

        $this->assertSame(3, LoanRequest::count());
        Storage::disk('local')->assertExists($path);
    }

    public function test_il_termine_segue_la_configurazione(): void
    {
        config(['privacy.retention_days' => 10]);
        $this->loan('FIN-2026-0001', 'richiesta', 11);
        $this->loan('FIN-2026-0002', 'richiesta', 9);

        $this->artisan('finanziamento:purge')->assertSuccessful();

        $this->assertSame(['FIN-2026-0002'], LoanRequest::pluck('code')->all());
    }

    public function test_le_conversazioni_attive_della_pratica_cancellata_vengono_chiuse(): void
    {
        $old = $this->loan('FIN-2026-0001', 'in_attesa_informativa', 31);
        Conversation::create([
            'wa_number' => '393331112222', 'flow' => 'perfezionamento', 'node' => 'nome', 'loan_request_id' => $old->id,
            'data' => ['cognome' => 'Rossi'], 'history' => [],
        ]);

        $this->artisan('finanziamento:purge')->assertSuccessful();

        $conv = Conversation::first();
        $this->assertSame('annullata', $conv->status);
        $this->assertSame([], $conv->data);
        $this->assertNull($conv->loan_request_id);
    }

    public function test_dry_run_non_cancella_nulla(): void
    {
        $this->loan('FIN-2026-0001', 'richiesta', 31);

        $this->artisan('finanziamento:purge', ['--dry-run' => true])->expectsOutputToContain('1')->assertSuccessful();

        $this->assertSame(1, LoanRequest::count());
    }

    public function test_e_pianificato_ogni_giorno(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('finanziamento:purge')->assertSuccessful();
    }

    public function test_eliminare_una_pratica_chiude_anche_le_conversazioni_aperte_e_ne_cancella_i_dati(): void
    {
        $loan = $this->loan('FIN-2026-0001', 'informativa_ricevuta', 1);
        $open = Conversation::create([
            'wa_number' => '393331112222', 'flow' => 'perfezionamento', 'node' => 'nome', 'loan_request_id' => $loan->id,
            'data' => ['cognome' => 'Rossi'], 'history' => [],
        ]);
        $other = Conversation::create(['wa_number' => '393339998888', 'flow' => 'richiesta', 'node' => 'importo', 'data' => ['prodotto' => 'mutuo'], 'history' => []]);

        $loan->delete();

        $this->assertSame('annullata', $open->fresh()->status);
        $this->assertSame([], $open->fresh()->data);
        $this->assertNull($open->fresh()->loan_request_id);
        $this->assertSame('attiva', $other->fresh()->status, 'le conversazioni di altre pratiche non si toccano');
        $this->assertSame(['prodotto' => 'mutuo'], $other->fresh()->data);
    }
}
