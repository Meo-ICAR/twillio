<?php

namespace Tests\Feature\Finanziamento;

use App\Jobs\AnalyzeAttachment;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class DocumentiFlowTest extends ConversationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function setStatus(LoanRequest $loan, string $code, string $status, ?string $note = null): PraticaDocument
    {
        PraticaDocument::populate($loan);
        $slot = $loan->praticaDocuments()->where('code', $code)->firstOrFail();
        $slot->update(['status' => $status]);
        if ($note) {
            $slot->addAnnotation('operatore', $note);
        }

        return $slot;
    }

    private function loan(string $code, array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => $code, 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'informativa_ricevuta',
            'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale'],
        ]);
    }

    private function attach(LoanRequest $loan, string $kind, string $status = 'ricevuto'): Attachment
    {
        return Attachment::create([
            'loan_request_id' => $loan->id, 'kind' => $kind, 'path' => "pratiche/{$loan->code}/{$kind}.jpg", 'mime' => 'image/jpeg',
            'status' => $status, 'received_at' => now(),
        ]);
    }

    public function test_senza_pratiche_lo_dice_e_non_apre_conversazioni(): void
    {
        $replies = $this->say('#menu_stato');

        $this->assertStringContainsString('nessuna pratica', $this->bodies($replies));
        $this->assertSame(0, Conversation::count());
    }

    public function test_stato_pratiche_propone_solo_le_pratiche_dell_agente(): void
    {
        $this->loan('FIN-2026-0001');
        $this->loan('FIN-2026-0002', ['status' => 'richiesta', 'privacy_received_at' => null, 'product' => 'mutuo']);
        $this->loan('FIN-2026-0003', ['agent_wa_number' => '393339998888']);

        $replies = $this->say('3');

        $this->assertSame('buttons', $replies[0]->kind); // fino a 3 pratiche; oltre, lista
        $this->assertSame(['FIN-2026-0002' => 'FIN-2026-0002', 'FIN-2026-0001' => 'FIN-2026-0001'], $replies[0]->options);
        $this->assertStringContainsString('FIN-2026-0001 · Prestito personale · informativa ricevuta', $replies[0]->body);
        $this->assertStringContainsString('FIN-2026-0002 · Mutuo · richiesta', $replies[0]->body);
        $this->assertStringNotContainsString('FIN-2026-0003', $replies[0]->body);
        $this->assertSame('pratica', Conversation::first()->node);
    }

    public function test_il_dettaglio_mostra_i_documenti_della_pratica_con_stato_e_annotazioni(): void
    {
        $loan = $this->loan('FIN-2026-0001');
        $this->setStatus($loan, 'documento_identita', 'ok');
        $this->setStatus($loan, 'codice_fiscale', 'rejected', 'Cognome: sul documento «BIANCHI», dichiarato «Rossi»');
        $this->setStatus($loan, 'estratto_conto', 'ricevuto');
        $this->setStatus($loan, 'informativa', 'ricevuto');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001');
        $body = $this->bodies($replies);

        $this->assertStringContainsString('✅ Documento d\'identità', $body);
        $this->assertStringContainsString('⚠️ Codice fiscale', $body);
        $this->assertStringContainsString('BIANCHI', $body);
        $this->assertStringContainsString('📎 Estratto conto bancario', $body);
        $this->assertStringContainsString('➖ Documento di reddito', $body);
        $this->assertStringContainsString('Obbligatori', $body);
        $this->assertStringContainsString('Facoltativi', $body);
        $this->assertStringContainsString('📎 Informativa firmata', $body, 'anche l\'informativa è un documento della pratica');
        $this->assertSame(['carica' => 'Carica documenti', 'altra' => 'Altra pratica'], end($replies)->options);
        $this->assertSame($loan->id, Conversation::first()->loan_request_id);
    }

    public function test_le_integrazioni_richieste_compaiono_con_la_nota_dell_istruttore(): void
    {
        $loan = $this->loan('FIN-2026-0001');
        PraticaDocument::populate($loan);
        $slot = $loan->praticaDocuments()->create(['code' => 'contratto_lavoro', 'name' => 'Contratto di lavoro', 'requirement' => 'integrativo', 'status' => 'integrazione_richiesta']);
        $slot->addAnnotation('operatore', 'Serve anche la pagina con la firma');

        $body = $this->bodies($this->say('#menu_stato', '#FIN-2026-0001'));

        $this->assertStringContainsString('Integrazioni richieste', $body);
        $this->assertStringContainsString('📝 Contratto di lavoro', $body);
        $this->assertStringContainsString('Serve anche la pagina con la firma', $body);
    }

    public function test_il_dettaglio_crea_i_documenti_se_la_pratica_non_li_ha_ancora(): void
    {
        $loan = $this->loan('FIN-2026-0001');

        $this->say('#menu_stato', '#FIN-2026-0001');

        $this->assertGreaterThanOrEqual(3, $loan->praticaDocuments()->count());
    }

    public function test_non_si_carica_senza_informativa(): void
    {
        $this->loan('FIN-2026-0002', ['status' => 'richiesta', 'privacy_received_at' => null]);

        $replies = $this->say('#menu_stato', '#FIN-2026-0002', '#carica');

        $this->assertStringContainsString('informativa', $this->bodies($replies));
        $this->assertSame('dettaglio', Conversation::first()->node);
    }

    public function test_non_si_puo_scegliere_la_pratica_di_un_altro_agente(): void
    {
        $this->loan('FIN-2026-0001');
        $this->loan('FIN-2026-0009', ['agent_wa_number' => '393339998888']);

        $replies = $this->say('#menu_stato', 'FIN-2026-0009');

        $this->assertStringContainsString('Scegli una delle opzioni', $this->bodies($replies));
        $this->assertSame('pratica', Conversation::first()->node);
    }

    public function test_si_propongono_solo_i_documenti_non_ancora_ok_piu_ho_finito(): void
    {
        $loan = $this->loan('FIN-2026-0001');
        $this->setStatus($loan, 'documento_identita', 'ok');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001', '#carica');

        $this->assertSame(['informativa', 'codice_fiscale', 'reddito', 'estratto_conto', 'fine'], array_keys(end($replies)->options));
        $this->assertSame('Estratto conto bancario', end($replies)->options['estratto_conto']);
    }

    public function test_caricamento_di_piu_documenti_collegati_al_documento_della_pratica(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $loan = $this->loan('FIN-2026-0001');

        $this->say('#menu_stato', '#FIN-2026-0001', '#carica', '#documento_identita');
        $replies = $this->say('media:M1:image/jpeg');

        $this->assertSame('tipo', Conversation::first()->node);
        $this->assertStringContainsString('Documento ricevuto', $this->bodies($replies));
        $attachment = $loan->attachments()->first();
        $slot = $loan->praticaDocuments()->where('code', 'documento_identita')->first();
        $this->assertSame('documento_identita', $attachment->kind);
        $this->assertSame($slot->id, $attachment->pratica_document_id);
        $this->assertSame('ricevuto', $slot->status);
        $this->assertNotNull($slot->received_at);
        Storage::disk('local')->assertExists($attachment->path);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class);

        $this->say('#reddito', 'media:M2:application/pdf', '#codice_fiscale', 'media:M3:image/png');
        $this->assertSame(3, $loan->attachments()->count());
        $this->assertSame(3, $loan->praticaDocuments()->where('status', 'ricevuto')->count());
    }

    public function test_un_documento_rifiutato_o_con_integrazione_richiesta_si_puo_ricaricare(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $loan = $this->loan('FIN-2026-0001');
        $this->setStatus($loan, 'codice_fiscale', 'rejected', 'Illeggibile');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001', '#carica');
        $this->assertContains('codice_fiscale', array_keys(end($replies)->options));

        $this->say('#codice_fiscale', 'media:M1:image/jpeg');
        $this->assertSame('ricevuto', $loan->praticaDocuments()->where('code', 'codice_fiscale')->value('status'));
    }

    public function test_la_pratica_resta_caricabile_in_giorni_diversi(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $loan = $this->loan('FIN-2026-0001');
        $this->say('#menu_stato', '#FIN-2026-0001', '#carica', '#documento_identita', 'media:M1:image/jpeg', 'menu');

        $this->travel(2)->days();
        $replies = $this->say('#menu_stato', '#FIN-2026-0001');

        $this->assertStringContainsString('📎 Documento d\'identità', $this->bodies($replies));
        $this->say('#carica', '#reddito', 'media:M2:image/jpeg');
        $this->assertSame(2, $loan->attachments()->count());
    }

    public function test_ho_finito_torna_al_dettaglio_aggiornato(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $this->loan('FIN-2026-0001');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001', '#carica', '#reddito', 'media:M1:image/jpeg', '#fine');

        $this->assertSame('dettaglio', Conversation::first()->node);
        $this->assertStringContainsString('📎 Documento di reddito', $this->bodies($replies));
    }

    public function test_file_non_valido_o_testo_vengono_rifiutati(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $this->loan('FIN-2026-0001');
        $this->say('#menu_stato', '#FIN-2026-0001', '#carica', '#documento_identita');

        $this->assertStringContainsString('Invia una foto o un PDF', $this->bodies($this->say('ecco')));
        $this->assertStringContainsString('Formato non accettato', $this->bodies($this->say('media:M1:video/mp4')));
        $this->assertSame('upload', Conversation::first()->node);
        Bus::assertNotDispatched(AnalyzeAttachment::class);
    }

    public function test_i_documenti_di_perfeziona_non_avviano_l_analisi(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $loan = $this->loan('FIN-2026-0007', ['status' => 'in_attesa_informativa', 'privacy_received_at' => null]);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'media:M1:application/pdf');

        $this->assertSame(1, $loan->attachments()->count());
        Bus::assertNotDispatched(AnalyzeAttachment::class);
    }
}
