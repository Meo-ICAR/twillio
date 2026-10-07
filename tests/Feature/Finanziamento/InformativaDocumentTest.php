<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use Database\Seeders\DocumentCatalogSeeder;

class InformativaDocumentTest extends ConversationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'personale'],
        ]);
    }

    public function test_il_riepilogo_non_elenca_l_informativa_tra_i_documenti_da_preparare_perche_ha_il_suo_paragrafo(): void
    {
        $this->loan();

        $body = $this->bodies($this->say('#menu_perfeziona', 'FIN-2026-0007', '#si'));

        $this->assertStringNotContainsString('• Informativa firmata', $body);
        $this->assertStringContainsString('Informativa privacy', $body, 'resta il paragrafo con il link');
    }

    public function test_l_informativa_inviata_diventa_il_documento_ricevuto_della_pratica_ma_non_ancora_verificata(): void
    {
        $loan = $this->loan();

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'media:M1:image/jpeg');

        $slot = $loan->praticaDocuments()->where('code', 'informativa')->first();
        $this->assertSame('ricevuto', $slot->status);
        $this->assertSame($slot->id, $loan->attachments()->where('kind', 'informativa')->value('pratica_document_id'));
        $this->assertNotNull($loan->fresh()->privacy_received_at);
        $this->assertNull($loan->fresh()->privacy_verified_at, 'la verifica la fa l\'AI o l\'operatore, non l\'invio');
        $this->assertSame('doc_identita', Conversation::first()->node, 'il dialogo prosegue senza aspettare');
    }

    public function test_un_informativa_rifiutata_viene_richiesta_di_nuovo(): void
    {
        $loan = $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $loan->praticaDocuments()->create(['code' => 'informativa', 'name' => 'Informativa firmata', 'requirement' => 'obbligatorio', 'status' => 'rejected']);

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertSame('informativa', Conversation::first()->node);
        $this->assertStringContainsString('informativa', strtolower(end($replies)->body));
    }

    public function test_un_informativa_in_verifica_non_si_richiede_di_nuovo(): void
    {
        $loan = $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $loan->praticaDocuments()->create(['code' => 'informativa', 'name' => 'Informativa firmata', 'requirement' => 'obbligatorio', 'status' => 'ricevuto']);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertSame('doc_identita', Conversation::first()->node);
    }
}
