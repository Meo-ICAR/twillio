<?php

namespace Tests\Feature\Documents;

use App\Models\Attachment;
use App\Models\FinanziamentoDocument;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformativaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_07_000019_add_informativa_to_document_catalog.php'))->up();
    }

    private function loan(string $code, array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + ['code' => $code, 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
    }

    public function test_il_catalogo_gia_in_uso_riceve_l_informativa_per_ogni_prodotto_senza_duplicati(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        FinanziamentoDocument::where('code', 'informativa')->delete();
        $products = FinanziamentoDocument::distinct()->pluck('product');

        $this->migrate();
        $this->migrate();

        foreach ($products as $product) {
            $rows = FinanziamentoDocument::where('product', $product)->where('code', 'informativa')->get();
            $this->assertCount(1, $rows, $product);
            $this->assertSame('informativa', $rows[0]->ai_kind);
            $this->assertSame('obbligatorio', $rows[0]->requirement);
            $this->assertSame(0, $rows[0]->sort_order);
        }
    }

    public function test_le_pratiche_con_l_informativa_gia_ricevuta_la_vedono_accettata_e_collegata(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        FinanziamentoDocument::where('code', 'informativa')->delete();
        $old = $this->loan('FIN-2026-0001', ['status' => 'informativa_ricevuta', 'privacy_received_at' => now()->subDays(3)]);
        $file = Attachment::create(['loan_request_id' => $old->id, 'kind' => 'informativa', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'received_at' => now()]);
        $fresh = $this->loan('FIN-2026-0002');

        $this->migrate();
        $this->migrate();

        $this->assertNotNull($old->fresh()->privacy_verified_at);
        $slots = $old->praticaDocuments()->where('code', 'informativa')->get();
        $this->assertCount(1, $slots);
        $this->assertSame('ok', $slots[0]->status);
        $this->assertSame($slots[0]->id, $file->fresh()->pratica_document_id);
        $this->assertSame('operatore', $slots[0]->annotations[0]['by']);
        $this->assertSame(FinanziamentoDocument::where('product', 'personale')->where('code', 'informativa')->value('id'), $slots[0]->finanziamento_document_id);

        $this->assertNull($fresh->fresh()->privacy_verified_at);
        $this->assertSame(0, $fresh->praticaDocuments()->count(), 'chi non ha mandato l\'informativa non ha nulla di accettato');
    }

    public function test_una_pratica_che_aveva_gia_la_verifica_non_cambia(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $at = now()->subDay()->startOfSecond();
        $loan = $this->loan('FIN-2026-0003', ['privacy_received_at' => now()->subDays(2), 'privacy_verified_at' => $at]);
        PraticaDocument::populate($loan);

        $this->migrate();

        $this->assertTrue($loan->fresh()->privacy_verified_at->equalTo($at));
        $this->assertSame('da_ricevere', $loan->praticaDocuments()->where('code', 'informativa')->value('status'));
    }
}
