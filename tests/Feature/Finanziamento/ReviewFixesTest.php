<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\Product;
use App\Services\Loans\EnergyQuoteEstimator;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\Artisan;

class ReviewFixesTest extends ConversationTestCase
{
    private function twoCompanies(): array
    {
        $first = Company::create(['name' => 'Mediatore']);
        Flow::query()->update(['company_id' => $first->id]);
        $this->seed(GbdEnergySeeder::class);

        return [$first, Company::where('name', 'GBD Energy')->first()];
    }

    public function test_flows_import_force_non_crea_percorsi_senza_company(): void
    {
        [$first, $gbd] = $this->twoCompanies();
        $before = Flow::count();

        Artisan::call('flows:import', ['--force' => true]);

        $this->assertSame(0, Flow::whereNull('company_id')->count());
        $this->assertSame($before, Flow::count());
        $this->assertSame(2, Flow::where('company_id', $gbd->id)->count());
    }

    public function test_la_modifica_preventivo_porta_la_company_dell_agente(): void
    {
        $company = Company::create(['name' => 'Titolare']);
        Flow::query()->update(['company_id' => $company->id]);
        Fornitore::create(['name' => 'Agente', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);
        $this->say(...['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500', '#no', '#no', '#conferma']);
        $code = LoanRequest::latest('id')->value('code');
        Conversation::query()->delete();

        $this->say('#modifica:'.$code);

        $this->assertSame($company->id, Conversation::latest('id')->first()->company_id);
    }

    public function test_la_migrazione_di_riempimento_assegna_la_company_piu_vecchia(): void
    {
        $first = Company::create(['name' => 'Prima']);
        Company::create(['name' => 'Seconda']);
        $loan = LoanRequest::create(['code' => 'X-1', 'agent_wa_number' => '39333', 'product' => 'p', 'status' => 'richiesta', 'answers' => []]);
        $conv = Conversation::create(['wa_number' => '39333', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);

        (require database_path('migrations/2026_10_14_000004_backfill_company_id.php'))->up();

        $this->assertSame($first->id, $loan->fresh()->company_id);
        $this->assertSame($first->id, $conv->fresh()->company_id);
    }

    public function test_i_prezzi_con_la_virgola_si_leggono_correttamente(): void
    {
        $company = Company::create(['name' => 'GBD', 'type' => 'ENERGY']);
        foreach ([['p0', '0,13'], ['p1', '0.12']] as [$code, $price]) {
            $company->products()->attach(Product::create(['type' => 'ENERGY', 'name' => $code, 'code' => $code, 'params' => ['fornitura' => 'luce', 'price' => $price]])->id);
        }

        $quote = app(EnergyQuoteEstimator::class)->quote(new LoanRequest(['product' => 'luce', 'answers' => ['spesa_mensile' => '28']]), $company);

        // 28*12/0.28 = 1200 kWh.
        $this->assertSame('p1', $quote['lines'][0]['product']->code);
        $this->assertEqualsWithDelta(144.0, $quote['annual_cost'], 0.01);
    }

    public function test_etichette_di_una_pratica_gbd_fuori_dal_bot(): void
    {
        [$first, $gbd] = $this->twoCompanies();

        $this->assertSame('Luce', LoanRequest::productLabels($gbd->id)['luce']);
        $this->assertArrayHasKey('Spesa mensile', LoanRequest::describe(['spesa_mensile' => '80'], 'richiesta', $gbd->id));
        $this->assertArrayHasKey('personale', LoanRequest::productLabels($first->id));
    }

    public function test_senza_percorso_richiesta_le_etichette_sono_vuote(): void
    {
        Company::create(['name' => 'Altra']);
        $vuota = Company::create(['name' => 'Vuota']);
        Flow::query()->update(['company_id' => Company::oldest('id')->value('id')]);

        $this->assertSame([], LoanRequest::productLabels($vuota->id));
    }
}
