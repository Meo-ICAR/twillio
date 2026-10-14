<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Fornitore;
use App\Models\Product;
use App\Services\Checks\CheckRegistry;
use App\Services\Flows\FlowImporter;
use Illuminate\Database\Seeder;

/** GBD Energy (contratti Edison via installatori) per la fase di prova. I prezzi dei prodotti sono DIMOSTRATIVI. */
class GbdEnergySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(['name' => 'GBD Energy'], [
            'type' => 'ENERGY',
            'email' => 'privacy@gbdenergy.example',
            'istruttoria_email' => 'contratti@gbdenergy.example',
            'menu' => ['options' => [
                'menu_richiedi' => 'Nuova offerta',
                'menu_perfeziona' => 'Sottoscrivi contratto',
            ]],
        ]);

        $this->call(EnergySupplierSeeder::class);
        app(CheckRegistry::class)->sync();
        app(FlowImporter::class)->import(company: $company, flows: config('energy.flows'));

        $offers = [
            ['code' => 'edison_luce_flex', 'name' => 'Edison Luce Flex (esempio)', 'params' => ['fornitura' => 'luce', 'price' => 0.13, 'fixed_monthly' => 8, 'bonus' => 0, 'duration_months' => 12, 'provider' => 'Edison']],
            ['code' => 'edison_luce_fisso', 'name' => 'Edison Luce Fisso (esempio)', 'params' => ['fornitura' => 'luce', 'price' => 0.15, 'fixed_monthly' => 0, 'bonus' => 40, 'duration_months' => 24, 'provider' => 'Edison']],
            ['code' => 'edison_gas_flex', 'name' => 'Edison Gas Flex (esempio)', 'params' => ['fornitura' => 'gas', 'price' => 0.55, 'fixed_monthly' => 7, 'bonus' => 0, 'duration_months' => 12, 'provider' => 'Edison']],
            ['code' => 'edison_gas_fisso', 'name' => 'Edison Gas Fisso (esempio)', 'params' => ['fornitura' => 'gas', 'price' => 0.62, 'fixed_monthly' => 0, 'bonus' => 30, 'duration_months' => 24, 'provider' => 'Edison']],
        ];
        foreach ($offers as $offer) {
            $product = Product::updateOrCreate(['type' => 'ENERGY', 'code' => $offer['code']], ['name' => $offer['name'], 'params' => $offer['params'], 'is_active' => true]);
            $company->products()->syncWithoutDetaching([$product->id]);
        }

        Fornitore::updateOrCreate(['tel' => '3927968199'], [
            'name' => 'Installatore 1', 'type' => 'Installatore fotovoltaico', 'is_active' => true, 'company_id' => $company->id,
        ]);
    }
}
