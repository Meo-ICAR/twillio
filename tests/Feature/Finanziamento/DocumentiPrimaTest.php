<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\PraticaField;
use App\Services\Conversation\ConversationEngine;
use App\Services\Documents\DocumentReader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Perfezionamento con i documenti prima dei dati: l'AI li legge dopo, l'agente conferma ciò che ha letto. */
class DocumentiPrimaTest extends ConversationTestCase
{
    /** @var array<string,array<string,mixed>> lettura per tipo di documento */
    public array $readings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentCatalogSeeder::class);
        config(['app.url' => 'https://twillio.hassisto.com']);
        $this->readings = [
            'informativa' => ['kind_detected' => 'informativa', 'legible' => true, 'matches_template' => true, 'signed' => true],
            'documento_identita' => ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035'],
            'codice_fiscale' => ['kind_detected' => 'codice_fiscale', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U'],
        ];
        $this->app->instance(DocumentReader::class, new class($this) implements DocumentReader
        {
            public function __construct(private DocumentiPrimaTest $test) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return $this->test->readings[$kind] ?? null;
            }
        });
    }

    private function loan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'richiesta', 'answers' => ['prodotto' => 'personale'],
        ]);
    }

    /** Esegue i lavori che partono dopo la risposta al webhook (e li dimentica, come a fine richiesta). */
    private function afterResponse(): void
    {
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function node(): string
    {
        return Conversation::where('status', 'attiva')->latest('id')->firstOrFail()->node;
    }

    /** Testi mandati a WhatsApp dai lavori in coda (non le risposte del dialogo). */
    private function sent(): string
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/messages'))
            ->map(fn ($r) => $r['text']['body'] ?? $r['interactive']['body']['text'] ?? '')
            ->implode("\n---\n");
    }

    /** Dalla pratica a: informativa inviata, documenti inviati, in attesa dei controlli. */
    private function sendDocuments(bool $analyzeBetween = false): array
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'media:I1:image/jpeg');
        $analyzeBetween && $this->afterResponse();
        $this->say('media:D1:image/jpeg', 'media:D2:image/jpeg');
        $analyzeBetween && $this->afterResponse();

        return $this->say('salta');
    }

    public function test_dopo_l_informativa_si_chiedono_prima_i_documenti_non_i_dati(): void
    {
        $this->loan();

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'media:I1:image/jpeg');

        $this->assertSame('doc_identita', $this->node());
        $this->assertStringContainsString('documento d\'identità', $this->bodies($replies));
        $this->assertStringContainsString('Lo controllo', $this->bodies($replies), 'l\'informativa è controllata dall\'AI');
    }

    public function test_un_documento_gia_ricevuto_non_si_richiede(): void
    {
        $loan = $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita')->update(['status' => 'ricevuto']);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertSame('doc_cf', $this->node(), 'il documento d\'identità c\'è già');
    }

    public function test_un_documento_rifiutato_si_richiede_di_nuovo(): void
    {
        $loan = $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita')->update(['status' => 'rejected']);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertSame('doc_identita', $this->node());
    }

    public function test_con_i_controlli_in_corso_il_dialogo_aspetta(): void
    {
        $replies = $this->sendDocuments();

        $this->assertSame('attesa_documenti', $this->node());
        $this->assertStringContainsString('controllando i documenti', $this->bodies($replies));
        $this->assertStringContainsString('avanti', $this->bodies($replies));
    }

    public function test_finiti_i_controlli_il_dialogo_riparte_da_solo_e_mostra_i_dati_letti(): void
    {
        $this->sendDocuments();

        $this->afterResponse();

        $this->assertSame('rivedi_dati', $this->node());
        $sent = $this->sent();
        $this->assertStringContainsString('Cognome: ROSSI', $sent);
        $this->assertStringContainsString('Codice fiscale: RSSMRA80A01H501U', $sent);
        $this->assertStringContainsString('Numero documento: AB123456', $sent);
        $this->assertStringContainsString('corretti', $sent);
    }

    public function test_se_restano_controlli_da_fare_non_riparte(): void
    {
        $this->sendDocuments();
        $loan = LoanRequest::first();
        // Un altro documento è ancora in analisi: non si può avanzare.
        $loan->attachments()->create(['kind' => 'reddito', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now(),
            'pratica_document_id' => PraticaDocument::populate($loan)->firstWhere('code', 'reddito')->id]);

        $replies = app(ConversationEngine::class)->resumeAfterAnalysis($loan->fresh());

        $this->assertSame([], $replies);
        $this->assertSame('attesa_documenti', $this->node());
    }

    public function test_un_controllo_rimasto_indietro_da_troppo_non_blocca_piu(): void
    {
        $this->sendDocuments();
        $loan = LoanRequest::first();
        $stale = $loan->attachments()->create(['kind' => 'reddito', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now()->subMinutes(30),
            'pratica_document_id' => PraticaDocument::populate($loan)->firstWhere('code', 'reddito')->id]);

        $this->afterResponse();

        $this->assertSame('rivedi_dati', $this->node());
        $this->assertSame('ricevuto', $stale->fresh()->status);
    }

    public function test_scrivendo_mentre_si_aspetta_il_bot_dice_che_sta_controllando(): void
    {
        $this->sendDocuments();

        $replies = $this->say('ciao');

        $this->assertSame('attesa_documenti', $this->node());
        $this->assertStringContainsString('controllando', $this->bodies($replies));
    }

    public function test_scrivendo_avanti_si_prosegue_senza_aspettare(): void
    {
        $this->sendDocuments();

        $replies = $this->say('Avanti');

        $this->assertSame('codice_fiscale', $this->node(), 'non c\'è ancora nulla da rivedere');
        $this->assertStringContainsString('Codice fiscale del cliente', $this->bodies($replies));
    }

    public function test_scrivendo_quando_i_controlli_sono_finiti_si_riparte_dal_messaggio(): void
    {
        $this->sendDocuments();
        // I controlli finiscono ma l'agente scrive prima che il lavoro rimetta in moto il dialogo.
        $this->app->forgetInstance(ConversationEngine::class);
        $loan = LoanRequest::first();
        $loan->attachments()->update(['status' => 'verificato']);
        PraticaField::propose($loan, ['cognome' => 'ROSSI'], $loan->attachments()->first());

        $replies = $this->say('ciao');

        $this->assertSame('rivedi_dati', $this->node());
        $this->assertStringContainsString('Cognome: ROSSI', $this->bodies($replies));
    }

    public function test_confermando_i_dati_letti_le_domande_note_si_saltano(): void
    {
        $this->sendDocuments();
        $this->afterResponse();

        $replies = $this->say('#conferma');

        $this->assertSame('residenza', $this->node(), 'codice fiscale, cognome, nome e luogo sono già noti');
        $this->assertStringContainsString('Indirizzo di residenza', $this->bodies($replies));
        $data = Conversation::first()->data;
        $this->assertSame('ROSSI', $data['cognome']);
        $this->assertSame('MARIO', $data['nome']);
        $this->assertSame('RSSMRA80A01H501U', $data['codice_fiscale']);
        $this->assertSame('01/01/1980', $data['data_nascita'], 'ricavata dal codice fiscale come se l\'avesse scritto l\'agente');
        $this->assertSame('confermato', PraticaField::where('key', 'cognome')->value('status'));
    }

    public function test_dopo_la_conferma_restano_da_chiedere_solo_i_dati_non_letti(): void
    {
        $this->sendDocuments();
        $this->afterResponse();
        $this->say('#conferma', 'Via Roma 1', '#celibe', '#ci');

        // Numero e scadenza del documento sono stati letti: si passa al telefono.
        $this->assertSame('telefono', $this->node());
        $this->assertSame('AB123456', Conversation::first()->data['documento_numero']);
        $this->assertSame('01/01/2035', Conversation::first()->data['documento_scadenza']);
    }

    public function test_correggendo_i_dati_letti_si_inseriscono_tutti_a_mano(): void
    {
        $this->sendDocuments();
        $this->afterResponse();

        $replies = $this->say('#correggi');

        $this->assertSame('codice_fiscale', $this->node());
        $this->assertStringContainsString('Codice fiscale del cliente', $this->bodies($replies));
        $this->assertArrayNotHasKey('cognome', Conversation::first()->data);
        $this->assertSame(['rifiutato'], PraticaField::pluck('status')->unique()->values()->all());
    }

    public function test_un_codice_fiscale_letto_non_valido_si_chiede_di_nuovo(): void
    {
        $this->readings['documento_identita']['fiscal_code'] = 'ABC123';
        $this->readings['codice_fiscale']['fiscal_code'] = 'ABC123';
        $this->sendDocuments();
        $this->afterResponse();

        $replies = $this->say('#conferma');

        $this->assertSame('codice_fiscale', $this->node());
        $this->assertStringContainsString('codice fiscale', strtolower($this->bodies($replies)));
        $this->assertArrayNotHasKey('codice_fiscale', Conversation::first()->data);
        $this->assertSame('rifiutato', PraticaField::where('key', 'codice_fiscale')->value('status'));
        $this->assertSame('ROSSI', Conversation::first()->data['cognome'], 'gli altri dati confermati restano');
    }

    public function test_un_codice_fiscale_letto_di_un_minorenne_si_chiede_di_nuovo(): void
    {
        $born = now()->subYears(10);
        $cf = 'RSSMRA'.$born->format('y').'ABCDEHLMPRST'[$born->month - 1].$born->format('d').'H501U';
        $this->readings['documento_identita']['fiscal_code'] = $cf;
        $this->readings['codice_fiscale']['fiscal_code'] = $cf;
        $this->sendDocuments();
        $this->afterResponse();

        $replies = $this->say('#conferma');

        $this->assertSame('codice_fiscale', $this->node());
        $this->assertStringContainsString('minorenne', $this->bodies($replies));
    }

    public function test_senza_nulla_da_rivedere_il_passo_si_salta(): void
    {
        $this->readings = ['informativa' => $this->readings['informativa']];
        $this->sendDocuments();

        $this->afterResponse();

        $this->assertNotSame('rivedi_dati', $this->node());
        $this->assertSame('codice_fiscale', $this->node());
    }

    public function test_non_si_invia_in_istruttoria_con_controlli_ancora_in_corso(): void
    {
        $this->sendDocuments();
        $this->afterResponse();
        $this->say('#conferma', 'Via Roma 1', '#celibe', '#ci', '+39 333 1234567', 'mario@example.com', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');
        $loan = LoanRequest::first();
        $loan->attachments()->create(['kind' => 'reddito', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now(),
            'pratica_document_id' => PraticaDocument::populate($loan)->firstWhere('code', 'reddito')->id]);

        $replies = $this->say('#conferma');

        $this->assertSame('riepilogo_p', $this->node());
        $this->assertStringContainsString('controllando', $this->bodies($replies));
        $this->assertNotSame('perfezionata', LoanRequest::first()->status);
        $this->assertNull(LoanRequest::first()->perfected_at);

        $loan->attachments()->where('kind', 'reddito')->update(['status' => 'verificato']);
        $replies = $this->say('#conferma');

        $this->assertStringContainsString('perfezionata', $this->bodies($replies));
        $this->assertNotNull(LoanRequest::first()->perfected_at);
    }

    public function test_il_riepilogo_mostra_lo_stato_dei_documenti(): void
    {
        $this->sendDocuments();
        $this->afterResponse();

        $replies = $this->say('#conferma', 'Via Roma 1', '#celibe', '#ci', '+39 333 1234567', 'mario@example.com', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        $body = $this->bodies($replies);
        $this->assertStringContainsString('✅ Documento d\'identità', $body);
        $this->assertStringContainsString('➖ Documento di reddito', $body);
    }

    public function test_l_informativa_non_conforme_lascia_i_documenti_in_attesa_e_l_agente_lo_sa(): void
    {
        $this->readings['informativa']['signed'] = false;
        $this->sendDocuments();

        $this->afterResponse();

        $this->assertSame('attesa_documenti', $this->node(), 'senza informativa valida i documenti non si leggono: si aspetta');
        $this->assertStringContainsString('non la posso accettare', $this->sent());
        $this->assertSame(2, Attachment::where('status', 'in_attesa_informativa')->count());
        $this->assertSame(0, PraticaField::count());
    }

    public function test_una_conversazione_non_in_attesa_non_viene_toccata(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');
        $before = Conversation::first()->node;

        $replies = app(ConversationEngine::class)->resumeAfterAnalysis(LoanRequest::first());

        $this->assertSame([], $replies);
        $this->assertSame($before, Conversation::first()->node);
    }

    public function test_senza_conversazione_attiva_non_succede_nulla(): void
    {
        $loan = $this->loan();

        $this->assertSame([], app(ConversationEngine::class)->resumeAfterAnalysis($loan));
    }

    public function test_i_controlli_in_corso_si_riconoscono_solo_se_l_analisi_e_attiva_e_recente(): void
    {
        $loan = $this->loan();
        $slot = PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita');
        $a = Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => 'documento_identita', 'path' => 'x', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now()]);

        $this->assertTrue($loan->hasPendingAnalyses(true));
        $this->assertFalse($loan->hasPendingAnalyses(false), 'senza AI nessuno analizza');

        $a->update(['status' => 'in_attesa_informativa']);
        $this->assertTrue($loan->hasPendingAnalyses(true));

        $a->update(['status' => 'verificato']);
        $this->assertFalse($loan->hasPendingAnalyses(true));

        Carbon::setTestNow(now()->addMinutes(20));
        $a->update(['status' => 'ricevuto', 'received_at' => now()->subMinutes(20)]);
        $this->assertFalse($loan->hasPendingAnalyses(true), 'dopo 15 minuti non si aspetta più');
        Carbon::setTestNow();
    }

    public function test_un_documento_senza_tipo_di_lettura_non_e_mai_in_corso(): void
    {
        $loan = $this->loan();
        $slot = PraticaDocument::populate($loan)->firstWhere('code', 'estratto_conto');
        Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => 'estratto_conto', 'path' => 'x', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now()]);

        $this->assertFalse($loan->hasPendingAnalyses(true));
    }
}
