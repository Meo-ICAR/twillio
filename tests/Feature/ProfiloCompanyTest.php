<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Fornitori\Pages\EditFornitore;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Company;
use App\Models\Fornitore;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ProfiloCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_la_company_ha_i_nuovi_campi(): void
    {
        foreach (['type', 'is_trial', 'trialend_at', 'trial_activated_at', 'activated_at', 'whatsapp_number', 'logo'] as $column) {
            $this->assertTrue(Schema::hasColumn('companies', $column), $column);
        }
        $this->assertSame('FINANCE', Company::create(['name' => 'A'])->fresh()->type, 'di default è finance');
        $this->assertFalse(Company::create(['name' => 'B'])->fresh()->is_trial);
    }

    public function test_la_prova_e_attiva_finche_non_scade(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->assertFalse(Company::make(['is_trial' => false])->isTrialActive());
        $this->assertTrue(Company::make(['is_trial' => true, 'trialend_at' => '2026-10-08'])->isTrialActive());
        $this->assertFalse(Company::make(['is_trial' => true, 'trialend_at' => '2026-10-07'])->isTrialActive());
        $this->assertTrue(Company::make(['is_trial' => true])->isTrialActive(), 'senza data di fine resta attiva');
    }

    public function test_si_modifica_la_company_dal_pannello_con_settore_prova_contratto_e_prodotti(): void
    {
        $company = Company::create(['name' => 'Hassisto']);
        $p1 = Product::create(['type' => 'FINANCE', 'name' => 'Cessione del quinto']);
        $p2 = Product::create(['type' => 'FINANCE', 'name' => 'Mutuo']);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'name' => 'Hassisto', 'type' => 'CALL CENTER', 'is_trial' => true, 'trial_activated_at' => '2026-10-01',
                'trialend_at' => '2026-10-31', 'activated_at' => '2026-11-05', 'whatsapp_number' => '+39 333 1234567', 'products' => [$p1->id, $p2->id],
            ])->call('save')->assertHasNoFormErrors();

        $company->refresh();
        $this->assertSame('CALL CENTER', $company->type);
        $this->assertTrue($company->is_trial);
        $this->assertSame('2026-10-31', $company->trialend_at->toDateString());
        $this->assertSame('2026-10-01', $company->trial_activated_at->toDateString());
        $this->assertSame('2026-11-05', $company->activated_at->toDateString());
        $this->assertSame('+39 333 1234567', $company->whatsapp_number);
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $company->products()->pluck('products.id')->all());
    }

    public function test_il_settore_deve_essere_uno_di_quelli_previsti(): void
    {
        $company = Company::create(['name' => 'Hassisto']);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['name' => 'Hassisto', 'type' => 'BANCA'])->call('save')->assertHasFormErrors(['type']);
    }

    public function test_si_crea_un_prodotto_e_lo_si_associa_a_piu_company(): void
    {
        Livewire::test(CreateProduct::class)->fillForm(['type' => 'HOTEL', 'name' => 'Check-in guidato'])->call('create')->assertHasNoFormErrors();

        $product = Product::sole();
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $a->products()->attach($product);
        $b->products()->attach($product);

        $this->assertSame(2, $product->companies()->count());
        $this->assertSame('HOTEL', $product->type);
        $this->get('/admin/products')->assertOk()->assertSee('Check-in guidato');
        $this->assertDatabaseCount('company_products', 2);
    }

    public function test_un_prodotto_non_si_associa_due_volte_alla_stessa_company(): void
    {
        $product = Product::create(['type' => 'FINANCE', 'name' => 'Mutuo']);
        $company = Company::create(['name' => 'A']);
        $company->products()->attach($product);

        $this->expectException(QueryException::class);
        $company->products()->attach($product);
    }

    public function test_eliminando_una_company_spariscono_le_sue_associazioni_ma_non_il_prodotto(): void
    {
        $product = Product::create(['type' => 'FINANCE', 'name' => 'Mutuo']);
        $company = Company::create(['name' => 'A']);
        $company->products()->attach($product);

        $company->delete();

        $this->assertDatabaseCount('company_products', 0);
        $this->assertSame(1, Product::count());
    }

    public function test_la_sigla_del_produttore_si_modifica_dal_pannello(): void
    {
        $f = Fornitore::create(['name' => 'Piero Meo', 'is_active' => true]);

        Livewire::test(EditFornitore::class, ['record' => $f->getRouteKey()])->fillForm(['sigla' => 'PMEO'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('PMEO', $f->fresh()->sigla);
        $this->get('/admin/produttori')->assertOk()->assertSee('Sigla')->assertSee('PMEO');
    }

    public function test_la_voce_prodotti_e_nel_menu_settings(): void
    {
        $this->assertSame('Settings', ProductResource::getNavigationGroup());
        $this->assertSame('Prodotti', ProductResource::getNavigationLabel());
    }
}
