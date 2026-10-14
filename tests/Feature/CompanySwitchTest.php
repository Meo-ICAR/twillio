<?php

namespace Tests\Feature;

use App\Filament\Resources\Flows\Pages\ListFlows;
use App\Models\Company;
use App\Models\Flow;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_il_pannello_mostra_lo_switch_con_le_company(): void
    {
        Company::create(['name' => 'GBD Energy']);

        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Tutte le aziende')->assertSee('GBD Energy');
    }

    public function test_la_scelta_resta_in_sessione(): void
    {
        Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);

        $this->actingAs($this->admin())->post('/admin/company-switch', ['company_id' => $b->id])->assertRedirect();

        $this->assertSame($b->id, session('current_company_id'));
    }

    public function test_tutte_toglie_il_filtro(): void
    {
        $a = Company::create(['name' => 'A']);

        $this->actingAs($this->admin())->withSession(['current_company_id' => $a->id])
            ->post('/admin/company-switch', ['company_id' => ''])->assertRedirect();

        $this->assertNull(CurrentCompany::id());
    }

    public function test_un_id_inesistente_in_sessione_vale_tutte(): void
    {
        session(['current_company_id' => 9999]);

        $this->assertNull(CurrentCompany::id());
    }

    public function test_senza_login_non_si_cambia_company(): void
    {
        $a = Company::create(['name' => 'A']);

        $this->post('/admin/company-switch', ['company_id' => $a->id])->assertRedirect();

        $this->assertNull(session('current_company_id'));
    }

    public function test_la_lista_percorsi_parte_filtrata_sulla_company_scelta(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $fa = Flow::create(['company_id' => $a->id, 'code' => 'richiesta', 'name' => 'Flusso A', 'start' => 'x', 'restart' => 'x']);
        $fb = Flow::create(['company_id' => $b->id, 'code' => 'richiesta', 'name' => 'Flusso B', 'start' => 'x', 'restart' => 'x']);

        $this->actingAs($this->admin())->withSession(['current_company_id' => $a->id]);

        Livewire::test(ListFlows::class)->assertCanSeeTableRecords([$fa])->assertCanNotSeeTableRecords([$fb]);
    }

    public function test_con_tutte_la_lista_percorsi_mostra_ogni_company(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $fa = Flow::create(['company_id' => $a->id, 'code' => 'richiesta', 'name' => 'Flusso A', 'start' => 'x', 'restart' => 'x']);
        $fb = Flow::create(['company_id' => $b->id, 'code' => 'richiesta', 'name' => 'Flusso B', 'start' => 'x', 'restart' => 'x']);

        $this->actingAs($this->admin());

        Livewire::test(ListFlows::class)->assertCanSeeTableRecords([$fa, $fb]);
    }
}
