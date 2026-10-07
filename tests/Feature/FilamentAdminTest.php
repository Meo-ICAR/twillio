<?php

namespace Tests\Feature;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\FinanziamentoDocuments\Pages\CreateFinanziamentoDocument;
use App\Filament\Resources\Fornitori\FornitoreResource;
use App\Filament\Resources\LoanRequests\Pages\EditLoanRequest;
use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Filament\Resources\LoanRequests\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\LoanRequests\RelationManagers\PraticaDocumentsRelationManager;
use App\Filament\Resources\LoanRequests\RelationManagers\PraticaFieldsRelationManager;
use App\Jobs\AnalyzeAttachment;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\PraticaField;
use App\Models\User;
use Database\Seeders\DocumentCatalogSeeder;
use Database\Seeders\FlowSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Filament::setCurrentPanel('admin');
    }

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k'],
            'personal' => ['nome' => 'Mario', 'cognome' => 'Rossi', 'iban' => 'IT60X0542811101000000123456'],
        ]);
    }

    private function login(): User
    {
        return tap(User::factory()->create(), fn ($u) => $this->actingAs($u));
    }

    public function test_gli_ospiti_vengono_mandati_al_login(): void
    {
        $this->get('/admin/loan-requests')->assertRedirect('/admin/login');
        $this->get('/admin/attachments')->assertRedirect('/admin/login');
    }

    public function test_un_utente_vede_le_pratiche_in_elenco(): void
    {
        $this->loan();
        $this->login();

        $this->get('/admin/loan-requests')->assertOk()->assertSee('FIN-2026-0001')->assertSee('Prestito personale');
    }

    public function test_la_pratica_mostra_risposte_leggibili_e_dati_personali(): void
    {
        $loan = $this->loan();
        $this->login();

        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()
            ->assertSee('5.000 - 10.000')
            ->assertSee('Mario')
            ->assertSee('IT60X0542811101000000123456');
    }

    public function test_pratiche_conversazioni_e_allegati_non_si_creano_a_mano(): void
    {
        $this->login();

        foreach (['loan-requests', 'conversations', 'attachments'] as $slug) {
            $this->get("/admin/{$slug}/create")->assertNotFound();
        }
    }

    public function test_le_conversazioni_mostrano_il_produttore_associato_al_cellulare(): void
    {
        Fornitore::create(['name' => 'Agenzia Bianchi', 'tel' => '+39 333 111 2222', 'is_active' => true]);
        Conversation::create(['wa_number' => '393331112222', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);
        Conversation::create(['wa_number' => '393339999999', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);
        $this->login();

        $this->get('/admin/conversations')->assertOk()->assertSee('Produttore')->assertSee('Agenzia Bianchi')->assertSee('393339999999');
    }

    public function test_i_produttori_sono_in_anagrafiche_in_sola_lettura(): void
    {
        $f = Fornitore::create(['name' => 'Agenzia Bianchi', 'nome' => 'Luca', 'tel' => '3331112222', 'is_active' => true]);
        $this->login();

        $this->get('/admin/produttori')->assertOk()->assertSee('Agenzia Bianchi')->assertSee('Cellulare');
        $this->get("/admin/produttori/{$f->id}")->assertOk()->assertSee('Luca');
        $this->get('/admin/produttori/create')->assertNotFound();
        $this->get("/admin/produttori/{$f->id}/edit")->assertNotFound();
        $this->assertSame('Produttori', FornitoreResource::getNavigationLabel());
        $this->assertSame('Anagrafiche', FornitoreResource::getNavigationGroup());
    }

    public function test_la_conversazione_non_mostra_i_dati_in_corso(): void
    {
        $conv = Conversation::create([
            'wa_number' => '393331112222', 'flow' => 'perfezionamento', 'node' => 'nome',
            'data' => ['cognome' => 'Segretissimo'], 'history' => [],
        ]);
        $this->login();

        $this->get('/admin/conversations')->assertOk()->assertSee('393331112222');
        $this->get("/admin/conversations/{$conv->id}")->assertOk()->assertDontSee('Segretissimo');
        $this->get("/admin/conversations/{$conv->id}/edit")->assertNotFound();
    }

    public function test_si_puo_cambiare_solo_lo_stato_della_pratica(): void
    {
        $loan = $this->loan();
        $this->login();

        Livewire::test(EditLoanRequest::class, ['record' => $loan->getRouteKey()])
            ->fillForm(['status' => 'perfezionata'])
            ->call('save')
            ->assertHasNoFormErrors();

        $loan->refresh();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertSame('FIN-2026-0001', $loan->code);
        $this->assertSame('Mario', $loan->personal['nome']);
    }

    public function test_si_scarica_l_allegato(): void
    {
        $loan = $this->loan();
        Storage::disk('local')->put('pratiche/FIN-2026-0001/informativa-x.pdf', 'PDFBYTES');
        $a = Attachment::create([
            'loan_request_id' => $loan->id, 'kind' => 'informativa', 'path' => 'pratiche/FIN-2026-0001/informativa-x.pdf',
            'mime' => 'application/pdf', 'received_at' => now(),
        ]);
        $this->login();

        Livewire::test(ListAttachments::class)
            ->assertCanSeeTableRecords([$a])
            ->callTableAction('download', $a)
            ->assertFileDownloaded('informativa-x.pdf', 'PDFBYTES');
    }

    public function test_eliminare_una_pratica_elimina_anche_i_file(): void
    {
        $loan = $this->loan();
        Storage::disk('local')->put('pratiche/FIN-2026-0001/informativa-x.pdf', 'PDFBYTES');
        Attachment::create([
            'loan_request_id' => $loan->id, 'kind' => 'informativa', 'path' => 'pratiche/FIN-2026-0001/informativa-x.pdf',
            'mime' => 'application/pdf', 'received_at' => now(),
        ]);

        $loan->delete();

        Storage::disk('local')->assertMissing('pratiche/FIN-2026-0001/informativa-x.pdf');
        $this->assertSame(0, Attachment::count());
    }

    public function test_gli_utenti_si_gestiscono_dal_pannello(): void
    {
        $this->login();

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();
    }

    public function test_l_azienda_titolare_si_modifica_dal_pannello_e_compare_nell_informativa(): void
    {
        $company = Company::create(['name' => 'Vecchia Spa']);
        $this->login();

        $this->get('/admin/companies')->assertOk()->assertSee('Vecchia Spa');

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'name' => 'Nuova Spa', 'address' => 'Via Verdi 2, Torino', 'email' => 'privacy@nuova.example',
                'dpo_email' => null, 'retention_perfected' => 'Dieci anni.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/privacy')->assertOk()->assertSee('Nuova Spa')->assertSee('Via Verdi 2, Torino')->assertSee('Dieci anni.');
    }

    public function test_si_crea_una_sola_azienda_e_non_si_elimina(): void
    {
        $this->login();

        $this->get('/admin/companies/create')->assertOk();

        Company::create(['name' => 'Unica Spa']);

        $this->get('/admin/companies/create')->assertForbidden();
    }

    public function test_la_scheda_pratica_evidenzia_i_dati_difformi_per_il_mediatore(): void
    {
        $loan = $this->loan();
        $loan->update(['personal' => [
            'nome' => 'Mario', 'cognome' => 'Bianchi',
            '_difformita' => ['Il cognome «Bianchi» darebbe «BNC», ma il codice fiscale contiene «RSS»'],
        ]]);
        $this->login();

        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()
            ->assertSee('Dati difformi da verificare')
            ->assertSee('darebbe')
            ->assertSee('Bianchi');
    }

    public function test_l_allegato_mostra_l_esito_del_controllo_e_le_difformita(): void
    {
        $loan = $this->loan();
        $a = Attachment::create([
            'loan_request_id' => $loan->id, 'kind' => 'documento_identita', 'path' => 'pratiche/x.jpg', 'mime' => 'image/jpeg',
            'status' => 'difforme', 'received_at' => now(),
            'analysis' => ['fields' => ['surname' => 'BIANCHI'], 'discrepancies' => ['Cognome: sul documento «BIANCHI», dichiarato «Rossi»']],
        ]);
        $this->login();

        $this->get("/admin/attachments/{$a->id}")->assertOk()
            ->assertSee('Difforme')
            ->assertSee('Difformità')
            ->assertSee('sul documento')
            ->assertSee('BIANCHI');
        $this->get('/admin/attachments')->assertOk()->assertSee('Difforme');

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class,
        ])->assertCanSeeTableRecords([$a])->assertTableColumnFormattedStateSet('status', 'Difforme', record: $a);
    }

    public function test_un_allegato_in_attesa_dell_informativa_si_riconosce_nell_elenco(): void
    {
        $loan = $this->loan();
        $a = Attachment::create([
            'loan_request_id' => $loan->id, 'kind' => 'documento_identita', 'path' => 'pratiche/x.jpg', 'mime' => 'image/jpeg',
            'status' => 'in_attesa_informativa', 'received_at' => now(),
        ]);
        $this->login();

        $this->get('/admin/attachments')->assertOk()->assertSee('In attesa informativa');
        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class])
            ->assertTableColumnFormattedStateSet('status', 'In attesa informativa', record: $a);
    }

    public function test_la_scheda_della_pratica_mostra_quando_l_informativa_e_stata_verificata(): void
    {
        $loan = $this->loan();
        $this->login();

        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()->assertSee('Informativa verificata il');

        $loan->update(['privacy_verified_at' => '2026-10-07 10:30:00']);
        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()->assertSee('10:30:00');
    }

    public function test_l_operatore_vede_i_dati_letti_dai_documenti_con_lo_stato_e_la_fonte(): void
    {
        $this->seed(FlowSeeder::class);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = $this->loan();
        $file = Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita')->id,
            'kind' => 'documento_identita', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'received_at' => now()]);
        PraticaField::propose($loan, ['cognome' => 'ROSSI', 'codice_fiscale' => 'RSSMRA80A01H501U'], $file);
        $loan->fields()->where('key', 'cognome')->first()->confirm();
        $this->login();

        Livewire::test(PraticaFieldsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class])
            ->assertCanSeeTableRecords($loan->fields)
            ->assertSee('ROSSI')
            ->assertSee('RSSMRA80A01H501U')
            ->assertSee('Codice fiscale')
            ->assertSee('Documento d\'identità')
            ->assertTableColumnFormattedStateSet('status', 'Confermato', record: $loan->fields()->where('key', 'cognome')->first())
            ->assertTableColumnFormattedStateSet('status', 'Proposto', record: $loan->fields()->where('key', 'codice_fiscale')->first());
    }

    public function test_i_dati_letti_non_si_modificano_dal_pannello(): void
    {
        $this->seed(FlowSeeder::class);
        $loan = $this->loan();
        $this->login();

        Livewire::test(PraticaFieldsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class])
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_approvando_l_informativa_dal_pannello_partono_i_documenti_in_attesa(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        Bus::fake();
        $loan = $this->loan();
        $slots = PraticaDocument::populate($loan);
        $waiting = Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slots->firstWhere('code', 'documento_identita')->id,
            'kind' => 'documento_identita', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'status' => 'in_attesa_informativa', 'received_at' => now()]);
        $this->login();

        $this->relationManager($loan)->callTableAction('approva', $slots->firstWhere('code', 'informativa'));

        $this->assertNotNull($loan->fresh()->privacy_verified_at);
        Bus::assertDispatchedAfterResponse(AnalyzeAttachment::class, fn (AnalyzeAttachment $job) => $job->attachmentId === $waiting->id);
    }

    public function test_il_catalogo_dei_documenti_si_gestisce_dal_pannello(): void
    {
        $this->seed(DocumentCatalogSeeder::class);
        $this->login();
        $this->get('/admin/finanziamento-documents')->assertOk()->assertSee('Documento d\'identità')->assertSee('Obbligatorio');

        $valid = ['product' => 'mutuo', 'code' => 'garanzia', 'name' => 'Garanzia', 'requirement' => 'facoltativo', 'is_active' => true, 'sort_order' => 9];
        Livewire::test(CreateFinanziamentoDocument::class)->fillForm($valid)->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('finanziamento_documents', ['product' => 'mutuo', 'code' => 'garanzia', 'requirement' => 'facoltativo']);

        Livewire::test(CreateFinanziamentoDocument::class)->fillForm(['name' => str_repeat('x', 25), 'code' => 'altro'] + $valid)->call('create')->assertHasFormErrors(['name']);
        Livewire::test(CreateFinanziamentoDocument::class)->fillForm($valid)->call('create')->assertHasFormErrors(['code']);
        Livewire::test(CreateFinanziamentoDocument::class)->fillForm(['code' => 'Con Spazi'] + $valid)->call('create')->assertHasFormErrors(['code']);
        Livewire::test(CreateFinanziamentoDocument::class)->fillForm(['requirement' => 'boh', 'code' => 'nuovo'] + $valid)->call('create')->assertHasFormErrors(['requirement']);
    }

    private function relationManager(PraticaDocument|LoanRequest $of)
    {
        $loan = $of instanceof PraticaDocument ? $of->loanRequest : $of;

        return Livewire::test(PraticaDocumentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class]);
    }

    private function slots(): PraticaDocument
    {
        $this->seed(DocumentCatalogSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        return PraticaDocument::populate($this->loan())->firstWhere('code', 'codice_fiscale');
    }

    public function test_l_operatore_vede_e_approva_i_documenti_della_pratica(): void
    {
        $slot = $this->slots();
        $user = $this->login();

        $this->relationManager($slot)
            ->assertCanSeeTableRecords($slot->loanRequest->praticaDocuments)
            ->assertTableColumnFormattedStateSet('status', 'Da ricevere', record: $slot)
            ->callTableAction('approva', $slot);

        $this->assertSame('ok', $slot->fresh()->status);
        $this->assertSame($user->id, $slot->fresh()->annotations[0]['user_id']);
    }

    public function test_il_rifiuto_richiede_una_nota_e_avvisa_l_agente(): void
    {
        $slot = $this->slots();
        $this->login();

        $this->relationManager($slot)->callTableAction('rifiuta', $slot, data: ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
        $this->assertSame('da_ricevere', $slot->fresh()->status);

        $this->relationManager($slot)->callTableAction('rifiuta', $slot, data: ['note' => 'La foto è tagliata'])->assertHasNoTableActionErrors();

        $this->assertSame('rejected', $slot->fresh()->status);
        $this->assertSame('La foto è tagliata', $slot->fresh()->lastAnnotation());
        Http::assertSent(fn ($r) => ($r['to'] ?? null) === '393331112222' && str_contains($r['text']['body'] ?? '', 'La foto è tagliata'));
    }

    public function test_la_richiesta_di_integrazione_su_un_documento(): void
    {
        $slot = $this->slots();
        $this->login();

        $this->relationManager($slot)->callTableAction('integrazione', $slot, data: ['note' => 'Serve la pagina con la firma']);

        $this->assertSame('integrazione_richiesta', $slot->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'integrazione'));
    }

    public function test_si_richiede_un_documento_integrativo_dal_catalogo_o_libero(): void
    {
        $slot = $this->slots();
        $loan = $slot->loanRequest;
        $this->login();

        $this->relationManager($loan)->callAction(TestAction::make('richiediIntegrativo')->table(), data: ['document' => 'contratto_lavoro', 'note' => 'Serve il contratto firmato']);
        $this->relationManager($loan)->callAction(TestAction::make('richiediIntegrativo')->table(), data: ['document' => '__altro', 'name' => 'Estratto conto cointestato', 'note' => 'Verifica il secondo intestatario']);

        $this->assertSame(['integrativo', 'integrazione_richiesta'], [
            $loan->praticaDocuments()->where('code', 'contratto_lavoro')->value('requirement'),
            $loan->praticaDocuments()->where('code', 'contratto_lavoro')->value('status'),
        ]);
        $this->assertSame('Estratto conto cointestato', $loan->praticaDocuments()->where('code', 'estratto-conto-cointestato')->value('name'));
        Http::assertSentCount(2);
    }

    public function test_il_nome_del_documento_libero_e_obbligatorio(): void
    {
        $slot = $this->slots();
        $this->login();

        $this->relationManager($slot)->callAction(TestAction::make('richiediIntegrativo')->table(), data: ['document' => '__altro', 'name' => '', 'note' => 'x'])
            ->assertHasActionErrors(['name' => 'required']);
    }

    public function test_la_cronologia_delle_annotazioni_e_visibile_con_l_autore(): void
    {
        $slot = $this->slots();
        $slot->addAnnotation('ai', 'Cognome: sul documento «BIANCHI»');
        $slot->addAnnotation('operatore', 'Confermo, serve un nuovo documento', 1);
        $this->login();

        $this->relationManager($slot)->assertSee('AI')->assertSee('BIANCHI')->assertSee('Operatore')->assertSee('Confermo');
    }

    public function test_l_istruttore_elimina_una_pratica_con_i_suoi_file_e_dati(): void
    {
        $loan = $this->loan();
        Storage::disk('local')->put('pratiche/FIN-2026-0001/informativa-x.pdf', 'PDF');
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'informativa', 'path' => 'pratiche/FIN-2026-0001/informativa-x.pdf', 'mime' => 'application/pdf', 'received_at' => now()]);
        $conv = Conversation::create(['wa_number' => '393331112222', 'flow' => 'perfezionamento', 'node' => 'nome', 'loan_request_id' => $loan->id, 'data' => ['cognome' => 'Rossi'], 'history' => []]);
        $this->login();

        Livewire::test(EditLoanRequest::class, ['record' => $loan->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertSame(0, LoanRequest::count());
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing('pratiche/FIN-2026-0001/informativa-x.pdf');
        $this->assertSame([], $conv->fresh()->data);
        $this->assertSame('annullata', $conv->fresh()->status);
    }
}
