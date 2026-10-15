<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Fornitore;
use App\Models\Product;
use App\Services\Checks\CheckRegistry;
use App\Services\Flows\FlowImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $this->seedCore($company);
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

    /**
     * Stati, task e requisiti di GBD nel core (tipo «Utenza»). Solo se la company GBD esiste nel core e lo schema è aggiornato (tasks.company_id).
     * La produzione dei documenti non si attiva qui: si accende a mano su `companies.produce_documents`, dopo i controlli.
     */
    private function seedCore(Company $company): void
    {
        $connection = config('unico-core.connection');
        $core = DB::connection($connection);
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('tasks') || ! $schema->hasColumn('tasks', 'company_id')) {
            return;
        }

        $coreId = $core->table('companies')->where('name', 'GBD Energy')
            ->when($schema->hasColumn('companies', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->value('id');
        if ($coreId === null) {
            return;
        }

        $company->forceFill(['core_company_id' => $coreId, 'core_tipo_prodotto' => 'Utenza'])->save();

        foreach (['INVIO IN ISTRUTTORIA', 'ALLA FIRMA', 'PERFEZIONARE'] as $order => $name) {
            $core->table('pratica_stati')->updateOrInsert(
                ['tipo_finanziamento' => 'Utenza', 'name' => $name],
                ['codice' => $name, 'ordine' => 10 + $order, 'is_rejected' => false, 'is_working' => true, 'is_estingued' => false, 'colore' => 'gray', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $ids = [];
        foreach (['Inserita' => 'Utenza · Inserita (GBD)', 'ALLA FIRMA' => 'Utenza · ALLA FIRMA (GBD)'] as $stato => $name) {
            $core->table('tasks')->updateOrInsert(
                ['company_id' => $coreId, 'trigger_value' => 'Utenza', 'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => $stato],
                ['name' => $name, 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
            $ids[$stato] = $core->table('tasks')->where('company_id', $coreId)->where('trigger_subvalue', $stato)->value('id');
        }

        $privacy = $core->table('document_types')->where('name', 'like', '%Infomativa privacy 2025%')->orderBy('id')->value('id');
        if ($privacy !== null) {
            $core->table('document_requirements')->updateOrInsert(
                ['task_id' => $ids['ALLA FIRMA'], 'document_type_id' => $privacy],
                ['is_required' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
