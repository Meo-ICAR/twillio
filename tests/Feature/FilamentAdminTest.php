<?php

namespace Tests\Feature;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\LoanRequests\Pages\EditLoanRequest;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
