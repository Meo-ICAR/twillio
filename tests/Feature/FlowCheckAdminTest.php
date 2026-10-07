<?php

namespace Tests\Feature;

use App\Filament\Resources\FlowChecks\Pages\EditFlowCheck;
use App\Filament\Resources\FlowChecks\Pages\ListFlowChecks;
use App\Models\FlowCheck;
use App\Models\User;
use App\Services\Checks\CheckRegistry;
use App\Services\Checks\IbanCheck;
use Database\Seeders\FlowSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlowCheckAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->seed(FlowSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function check(string $code): FlowCheck
    {
        return FlowCheck::where('code', $code)->firstOrFail();
    }

    public function test_l_elenco_mostra_nome_descrizione_e_dove_e_usato_ogni_controllo(): void
    {
        $this->get('/admin/flow-checks')->assertOk()
            ->assertSee('Codice fiscale')
            ->assertSee('IBAN')
            ->assertSee('Età minima')
            ->assertSee('perfezionamento.iban')
            ->assertSee('checksum')
            ->assertSee('Tipo di documento')
            ->assertSee('Informativa firmata');
        $this->get('/admin/flow-checks/create')->assertNotFound();
    }

    public function test_il_pulsante_cerca_nuovi_controlli_riporta_nell_elenco_quelli_mancanti(): void
    {
        $this->check('iban')->delete();
        $this->assertSame(6, FlowCheck::count());

        Livewire::test(ListFlowChecks::class)->callAction(TestAction::make('cercaNuovi'))->assertHasNoActionErrors();

        $this->assertSame(IbanCheck::class, FlowCheck::where('code', 'iban')->value('class'));
        $this->assertSame(7, FlowCheck::count());
    }

    public function test_si_disattiva_un_controllo_non_usato_e_sparisce_dalla_select(): void
    {
        $row = FlowCheck::create(['code' => 'prova', 'class' => IbanCheck::class, 'sort_order' => 9]);

        Livewire::test(EditFlowCheck::class, ['record' => $row->getRouteKey()])->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();

        $this->assertFalse($row->fresh()->is_active);
        $this->assertArrayNotHasKey('prova', app(CheckRegistry::class)->all());
    }

    public function test_non_si_disattiva_un_controllo_usato_da_una_domanda(): void
    {
        $row = $this->check('iban');

        Livewire::test(EditFlowCheck::class, ['record' => $row->getRouteKey()])->fillForm(['is_active' => false])->call('save');

        $this->assertTrue($row->fresh()->is_active);
    }

    public function test_non_si_elimina_un_controllo_usato_ma_si_elimina_uno_libero(): void
    {
        $used = $this->check('iban');
        $free = FlowCheck::create(['code' => 'prova', 'class' => IbanCheck::class, 'sort_order' => 9]);

        Livewire::test(ListFlowChecks::class)->callTableAction(DeleteAction::class, $used);
        $this->assertNotNull($used->fresh());

        Livewire::test(ListFlowChecks::class)->callTableAction(DeleteAction::class, $free);
        $this->assertNull($free->fresh());
    }

    public function test_il_codice_non_si_cambia_perche_le_domande_lo_usano(): void
    {
        $row = $this->check('iban');

        Livewire::test(EditFlowCheck::class, ['record' => $row->getRouteKey()])->fillForm(['code' => 'altro_nome', 'sort_order' => 5])->call('save');

        $this->assertSame('iban', $row->fresh()->code);
        $this->assertSame(5, $row->fresh()->sort_order);
    }
}
