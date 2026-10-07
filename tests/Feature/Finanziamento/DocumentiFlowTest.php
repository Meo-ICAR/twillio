<?php

namespace Tests\Feature\Finanziamento;

use App\Jobs\AnalyzeAttachment;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class DocumentiFlowTest extends ConversationTestCase
{
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

    public function test_il_dettaglio_mostra_la_checklist_dei_documenti(): void
    {
        $loan = $this->loan('FIN-2026-0001');
        $this->attach($loan, 'documento_identita', 'verificato');
        $this->attach($loan, 'codice_fiscale', 'difforme');
        $this->attach($loan, 'informativa');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001');
        $body = $this->bodies($replies);

        $this->assertStringContainsString('✅ Documento d\'identità', $body);
        $this->assertStringContainsString('⚠️ Codice fiscale', $body);
        $this->assertStringContainsString('➖ Documento di reddito', $body);
        $this->assertStringNotContainsString('Informativa', $body);
        $this->assertSame(['carica' => 'Carica documenti', 'altra' => 'Altra pratica'], end($replies)->options);
        $this->assertSame($loan->id, Conversation::first()->loan_request_id);
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

    public function test_caricamento_di_piu_documenti_con_analisi_dopo_la_risposta(): void
    {
        Bus::fake([AnalyzeAttachment::class]);
        $loan = $this->loan('FIN-2026-0001');

        $replies = $this->say('#menu_stato', '#FIN-2026-0001', '#carica');
        $this->assertSame(['documento_identita', 'codice_fiscale', 'reddito', 'fine'], array_keys(end($replies)->options));

        $this->say('#documento_identita');
        $replies = $this->say('media:M1:image/jpeg');

        $this->assertSame('tipo', Conversation::first()->node);
        $this->assertStringContainsString('Documento ricevuto', $this->bodies($replies));
        $attachment = $loan->attachments()->first();
        $this->assertSame('documento_identita', $attachment->kind);
        $this->assertSame('ricevuto', $attachment->status);
        Storage::disk('local')->assertExists($attachment->path);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class);

        $this->say('#reddito', 'media:M2:application/pdf', '#codice_fiscale', 'media:M3:image/png');
        $this->assertSame(3, $loan->attachments()->count());
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
