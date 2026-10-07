<?php

namespace Tests\Feature\TestMode;

use App\Filament\Resources\Flows\Pages\EditFlow;
use App\Filament\Resources\Flows\Pages\ListFlows;
use App\Filament\Resources\LoanRequests\Pages\ListLoanRequests;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Flow;
use App\Models\LoanRequest;
use App\Models\User;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlowTestModeAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->seed(FlowSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function prod(string $code = 'richiesta'): Flow
    {
        return Flow::where('code', $code)->where('is_test', false)->firstOrFail();
    }

    private function copy(string $code = 'richiesta'): ?Flow
    {
        return Flow::where('code', $code)->where('is_test', true)->first();
    }

    public function test_l_elenco_distingue_produzione_e_prova(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());

        $this->get('/admin/flows')->assertOk()->assertSee('Produzione')->assertSee('Prova')->assertSee('Richiedi Finanziamento (prova)');
    }

    public function test_si_crea_la_copia_di_prova_dal_pannello_una_sola_volta(): void
    {
        $prod = $this->prod();

        Livewire::test(ListFlows::class)->callTableAction('creaCopiaProva', $prod)->assertHasNoTableActionErrors();

        $this->assertNotNull($this->copy());
        Livewire::test(ListFlows::class)->assertTableActionHidden('creaCopiaProva', $prod)->assertTableActionVisible('ricreaCopiaProva', $prod);
    }

    public function test_si_rifa_la_copia_di_prova_dalla_produzione(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $this->copy()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'MODIFICATA']);

        Livewire::test(ListFlows::class)->callTableAction('ricreaCopiaProva', $this->prod());

        $this->assertSame(config('finanziamento.flows.richiesta.nodes.importo.prompt'), $this->copy()->nodes()->where('code', 'importo')->value('prompt'));
    }

    public function test_le_azioni_di_prova_non_compaiono_dove_non_hanno_senso(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $copy = $this->copy();

        Livewire::test(ListFlows::class)
            ->assertTableActionHidden('creaCopiaProva', $copy)
            ->assertTableActionHidden('ricreaCopiaProva', $copy)
            ->assertTableActionHidden('pubblica', $this->prod())
            ->assertTableActionVisible('pubblica', $copy);
    }

    public function test_si_pubblica_la_prova_in_produzione(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $this->copy()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'Quanto serve, esattamente?']);

        Livewire::test(ListFlows::class)->callTableAction('pubblica', $this->copy())->assertHasNoTableActionErrors();

        $this->assertSame('Quanto serve, esattamente?', app(FlowRepository::class)->node('richiesta', 'importo')['prompt']);
    }

    public function test_una_prova_con_errori_non_si_pubblica_e_la_produzione_resta_com_era(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $this->copy()->nodes()->where('code', 'importo')->first()->jumps()->update(['go_to' => 'domanda_fantasma']);

        Livewire::test(ListFlows::class)->callTableAction('pubblica', $this->copy());

        $this->assertSame('durata', app(FlowRepository::class)->node('richiesta', 'importo')['next']);
    }

    public function test_la_copia_di_prova_si_elimina_la_produzione_no(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());

        Livewire::test(EditFlow::class, ['record' => $this->prod()->getRouteKey()])->assertActionHidden(DeleteAction::class);
        Livewire::test(EditFlow::class, ['record' => $this->copy()->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertNull($this->copy());
        $this->assertNotNull($this->prod());
    }

    public function test_si_associa_un_numero_whatsapp_a_un_utente(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['whatsapp_number' => '+39 333 111 2222'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('+39 333 111 2222', $user->fresh()->whatsapp_number);
        $this->assertTrue(User::hasTesterNumber('393331112222'));
    }

    public function test_lo_stesso_numero_non_si_associa_a_due_utenti_in_forme_diverse(): void
    {
        User::factory()->create(['whatsapp_number' => '+39 333 111 2222']);
        $other = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['whatsapp_number' => '3331112222'])->call('save')->assertHasFormErrors(['whatsapp_number']);

        $this->assertNull($other->fresh()->whatsapp_number);
    }

    public function test_un_utente_puo_non_avere_il_numero_e_si_puo_toglierlo(): void
    {
        $user = User::factory()->create(['whatsapp_number' => '3331112222']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->fillForm(['whatsapp_number' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($user->fresh()->whatsapp_number);
        $this->assertFalse(User::hasTesterNumber('3331112222'));
    }

    public function test_le_pratiche_di_prova_si_riconoscono_e_si_filtrano(): void
    {
        $real = LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $test = LoanRequest::create(['code' => 'TST-2026-0001', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'is_test' => true, 'answers' => []]);

        Livewire::test(ListLoanRequests::class)
            ->assertCanSeeTableRecords([$real, $test])
            ->filterTable('is_test', true)->assertCanSeeTableRecords([$test])->assertCanNotSeeTableRecords([$real])
            ->filterTable('is_test', false)->assertCanSeeTableRecords([$real])->assertCanNotSeeTableRecords([$test]);
    }
}
