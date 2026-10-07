<?php

namespace Tests\Feature\Documents;

use App\Models\Attachment;
use App\Models\FinanziamentoDocument;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function loan(string $product = 'personale'): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-'.str_pad((string) (LoanRequest::count() + 1), 4, '0', STR_PAD_LEFT), 'agent_wa_number' => '393331112222',
            'product' => $product, 'status' => 'richiesta', 'answers' => ['prodotto' => $product],
        ]);
    }

    public function test_le_tabelle_hanno_le_colonne_attese(): void
    {
        $this->assertTrue(Schema::hasColumns('finanziamento_documents', ['product', 'code', 'name', 'description', 'requirement', 'ai_kind', 'sort_order', 'is_active']));
        $this->assertTrue(Schema::hasColumns('pratica_documents', ['loan_request_id', 'finanziamento_document_id', 'code', 'name', 'requirement', 'status', 'annotations', 'received_at', 'reviewed_at']));
        $this->assertTrue(Schema::hasColumn('attachments', 'pratica_document_id'));
    }

    public function test_un_documento_per_prodotto_e_codice(): void
    {
        FinanziamentoDocument::create(['product' => 'mutuo', 'code' => 'x', 'name' => 'X', 'requirement' => 'obbligatorio']);

        $this->expectException(QueryException::class);
        FinanziamentoDocument::create(['product' => 'mutuo', 'code' => 'x', 'name' => 'Y', 'requirement' => 'facoltativo']);
    }

    public function test_il_catalogo_iniziale_copre_ogni_prodotto_con_i_documenti_base(): void
    {
        $this->seed(DocumentCatalogSeeder::class);

        foreach (array_keys(config('finanziamento.flows.richiesta.nodes.prodotto.options')) as $product) {
            $required = FinanziamentoDocument::where('product', $product)->where('requirement', 'obbligatorio')->pluck('code')->all();
            $this->assertContains('documento_identita', $required, $product);
            $this->assertContains('codice_fiscale', $required, $product);
            $this->assertTrue(FinanziamentoDocument::where('product', $product)->where('requirement', 'integrativo')->exists(), "$product: nessun integrativo");
        }
        foreach (FinanziamentoDocument::all() as $doc) {
            $this->assertLessThanOrEqual(24, mb_strlen($doc->name), "{$doc->product}.{$doc->code}: nome oltre 24 caratteri (limite WhatsApp)");
            $this->assertContains($doc->requirement, ['obbligatorio', 'facoltativo', 'integrativo']);
        }
    }

    public function test_il_seeder_non_duplica_e_non_sovrascrive_le_modifiche(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $count = FinanziamentoDocument::count();
        FinanziamentoDocument::where('product', 'mutuo')->where('code', 'documento_identita')->update(['name' => 'Documento modificato']);

        $this->seed(DocumentCatalogSeeder::class);

        $this->assertSame($count, FinanziamentoDocument::count());
        $this->assertSame('Documento modificato', FinanziamentoDocument::where('product', 'mutuo')->where('code', 'documento_identita')->value('name'));
    }

    public function test_la_pratica_riceve_solo_i_documenti_obbligatori_e_facoltativi_del_suo_prodotto(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        FinanziamentoDocument::where('product', 'personale')->where('code', 'estratto_conto')->update(['is_active' => false]);
        $loan = $this->loan('personale');

        PraticaDocument::populate($loan);

        $slots = $loan->praticaDocuments()->orderBy('id')->get();
        $this->assertContains('documento_identita', $slots->pluck('code')->all());
        $this->assertNotContains('estratto_conto', $slots->pluck('code')->all(), 'catalogo disattivato');
        $this->assertNotContains('contratto_lavoro', $slots->pluck('code')->all(), 'gli integrativi si richiedono, non si creano');
        $this->assertNotContains('compromesso', $slots->pluck('code')->all(), 'documento di un altro prodotto');
        $this->assertSame(['da_ricevere'], $slots->pluck('status')->unique()->values()->all());
        $first = $slots->firstWhere('code', 'documento_identita');
        $this->assertSame('Documento d\'identità', $first->name);
        $this->assertSame('obbligatorio', $first->requirement);
        $this->assertNotNull($first->finanziamento_document_id);
    }

    public function test_populate_e_ripetibile_e_rispetta_l_ordine_del_catalogo(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan('mutuo');

        PraticaDocument::populate($loan);
        $count = $loan->praticaDocuments()->count();
        $loan->praticaDocuments()->where('code', 'documento_identita')->update(['status' => 'ok']);
        PraticaDocument::populate($loan);

        $this->assertSame($count, $loan->praticaDocuments()->count());
        $this->assertSame('ok', $loan->praticaDocuments()->where('code', 'documento_identita')->value('status'));
        $this->assertSame('documento_identita', $loan->praticaDocuments()->orderBy('sort_order')->value('code'));
    }

    public function test_le_annotazioni_hanno_autore_data_e_sono_cifrate(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $slot = PraticaDocument::populate($this->loan())->first();
        $user = User::factory()->create();

        $slot->addAnnotation('ai', 'Cognome: sul documento «BIANCHI», dichiarato «Rossi»');
        $slot->addAnnotation('operatore', 'Serve la pagina con la firma', $user->id);

        $notes = $slot->fresh()->annotations;
        $this->assertCount(2, $notes);
        $this->assertSame(['ai', null], [$notes[0]['by'], $notes[0]['user_id']]);
        $this->assertSame(['operatore', $user->id], [$notes[1]['by'], $notes[1]['user_id']]);
        $this->assertNotEmpty($notes[1]['at']);
        $this->assertStringNotContainsString('BIANCHI', DB::table('pratica_documents')->value('annotations'));
        $this->assertSame('Serve la pagina con la firma', $slot->fresh()->lastAnnotation());
    }

    public function test_un_autore_non_valido_e_rifiutato(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $slot = PraticaDocument::populate($this->loan())->first();

        $this->expectException(\InvalidArgumentException::class);
        $slot->addAnnotation('chiunque', 'x');
    }

    public function test_la_pratica_e_completa_solo_con_tutti_gli_obbligatori_ok_e_nessuna_integrazione_aperta(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);
        $this->assertFalse($loan->documentsComplete());

        $loan->praticaDocuments()->where('requirement', 'obbligatorio')->update(['status' => 'ok']);
        $this->assertTrue($loan->fresh()->documentsComplete(), 'i facoltativi non bloccano');

        $loan->praticaDocuments()->create(['code' => 'perizia', 'name' => 'Perizia', 'requirement' => 'integrativo', 'status' => 'integrazione_richiesta']);
        $this->assertFalse($loan->fresh()->documentsComplete(), 'un\'integrazione richiesta e non ricevuta blocca');
    }

    public function test_gli_allegati_si_collegano_al_documento_della_pratica(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        $slot = PraticaDocument::populate($loan)->first();

        $a = Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => $slot->code, 'path' => 'x', 'mime' => 'image/jpeg', 'received_at' => now()]);

        $this->assertTrue($slot->attachments->first()->is($a));
        $this->assertTrue($a->praticaDocument->is($slot));
    }

    public function test_eliminando_la_pratica_spariscono_i_suoi_documenti_ma_non_il_catalogo(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);
        $catalog = FinanziamentoDocument::count();

        $loan->delete();

        $this->assertSame(0, PraticaDocument::count());
        $this->assertSame($catalog, FinanziamentoDocument::count());
    }

    public function test_un_documento_fuori_catalogo_resta_dopo_la_modifica_del_catalogo(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $slot = PraticaDocument::populate($this->loan())->first();

        FinanziamentoDocument::find($slot->finanziamento_document_id)->delete();

        $this->assertNull($slot->fresh()->finanziamento_document_id);
        $this->assertSame('Documento d\'identità', $slot->fresh()->name);
    }

    public function test_l_operatore_approva_rifiuta_e_chiede_integrazioni(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $slot = PraticaDocument::populate($this->loan())->first();
        $user = User::factory()->create();

        $slot->approve($user->id);
        $this->assertSame('ok', $slot->fresh()->status);
        $this->assertNotNull($slot->fresh()->reviewed_at);
        $this->assertSame('operatore', $slot->fresh()->annotations[0]['by']);

        $slot->reject('Documento scaduto', $user->id);
        $this->assertSame('rejected', $slot->fresh()->status);
        $this->assertSame('Documento scaduto', $slot->fresh()->lastAnnotation());
        $this->assertSame($user->id, $slot->fresh()->annotations[1]['user_id']);

        $slot->requestIntegration('Serve la pagina con la firma', $user->id);
        $this->assertSame('integrazione_richiesta', $slot->fresh()->status);
        $this->assertSame('Serve la pagina con la firma', $slot->fresh()->lastAnnotation());
    }

    public function test_rifiuto_e_integrazione_richiedono_una_nota(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $slot = PraticaDocument::populate($this->loan())->first();

        foreach (['reject', 'requestIntegration'] as $method) {
            try {
                $slot->{$method}('   ', 1);
                $this->fail("$method senza nota deve fallire");
            } catch (\InvalidArgumentException) {
                $this->assertSame('da_ricevere', $slot->fresh()->status);
            }
        }
    }

    public function test_si_richiede_un_documento_integrativo_dal_catalogo_o_libero(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);
        $user = User::factory()->create();

        $fromCatalog = $loan->requestIntegrativeDocument('contratto_lavoro', 'Contratto di lavoro', 'Serve il contratto firmato', $user->id);
        $free = $loan->requestIntegrativeDocument(null, 'Estratto conto cointestato', 'Verifica il secondo intestatario', $user->id);

        $this->assertSame(['integrativo', 'integrazione_richiesta', 'contratto_lavoro'], [$fromCatalog->requirement, $fromCatalog->status, $fromCatalog->code]);
        $this->assertNotNull($fromCatalog->finanziamento_document_id);
        $this->assertSame('Serve il contratto firmato', $fromCatalog->lastAnnotation());
        $this->assertSame('integrativo', $free->requirement);
        $this->assertNull($free->finanziamento_document_id);
        $this->assertSame('estratto-conto-cointestato', $free->code);
        $this->assertFalse($loan->documentsComplete());
    }

    public function test_richiedere_di_nuovo_lo_stesso_integrativo_riusa_il_documento(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        PraticaDocument::populate($loan);

        $loan->requestIntegrativeDocument('contratto_lavoro', 'Contratto di lavoro', 'Prima richiesta', 1);
        $again = $loan->requestIntegrativeDocument('contratto_lavoro', 'Contratto di lavoro', 'Seconda richiesta', 1);

        $this->assertSame(1, $loan->praticaDocuments()->where('code', 'contratto_lavoro')->count());
        $this->assertSame('Seconda richiesta', $again->lastAnnotation());
        $this->assertCount(2, $again->annotations);
    }
}
