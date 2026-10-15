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

        // Offerte Edison. Prezzi (€/kWh, €/Smc "tutto compreso"), quote e bonus sono DIMOSTRATIVI; i limiti di consumo sono quelli di listino.
        $offers = [];
        $add = function (string $code, string $name, string $segmento, array $luce, array $gas, array $extra = []) use (&$offers) {
            foreach (['luce' => $luce, 'gas' => $gas] as $fornitura => $values) {
                $offers[] = ['code' => "{$code}_{$fornitura}", 'name' => $name, 'params' => $values + $extra + ['fornitura' => $fornitura, 'segmento' => $segmento, 'provider' => 'Edison']];
            }
        };
        // Privati.
        $add('edison_dynamic', 'Edison Dynamic', 'privato', ['price' => 0.255, 'fixed_monthly' => 6], ['price' => 1.00, 'fixed_monthly' => 6], ['duration_months' => 12]);
        $add('edison_world_plus', 'Edison World Plus', 'privato', ['price' => 0.250, 'fixed_monthly' => 9], ['price' => 0.98, 'fixed_monthly' => 9], ['duration_months' => 12]);
        $add('edison_superflex', 'Edison SuperFlex', 'privato', ['price' => 0.262, 'fixed_monthly' => 4, 'bonus' => 20], ['price' => 1.04, 'fixed_monthly' => 4, 'bonus' => 20], ['duration_months' => 12]);
        $add('edison_web_easyfix', 'Edison Web / EasyFix (prezzo fisso)', 'privato', ['price' => 0.265, 'bonus' => 30], ['price' => 1.05, 'bonus' => 30], ['duration_months' => 24]);
        $add('edison_sweet', 'Edison Sweet', 'privato', ['price' => 0.258, 'bonus' => 40], ['price' => 1.02, 'bonus' => 40], ['duration_months' => 24, 'residenza' => 'seconda']);
        // Aziende e P.IVA, bassa tensione.
        $add('edison_dynamic_business', 'Edison Dynamic Business', 'azienda', ['price' => 0.215, 'fixed_monthly' => 10, 'max_kwh' => 5000], ['price' => 0.85, 'fixed_monthly' => 10, 'max_smc' => 2500], ['tensione' => 'bt', 'duration_months' => 12]);
        $add('edison_world_business', 'Edison World Business', 'azienda', ['price' => 0.208, 'fixed_monthly' => 15, 'max_kwh' => 10000], ['price' => 0.83, 'fixed_monthly' => 15, 'max_smc' => 5000], ['tensione' => 'bt', 'duration_months' => 12]);
        $add('edison_placet_business', 'Edison Placet Business', 'azienda', ['price' => 0.225], ['price' => 0.88], ['tensione' => 'bt', 'duration_months' => 12]);
        // Media tensione (grandi imprese): piani su misura, qui indicativi.
        $add('edison_mt_indicizzato', 'Edison Business MT Indicizzato', 'azienda', ['price' => 0.150, 'fixed_monthly' => 60], ['price' => 0.60, 'fixed_monthly' => 60], ['tensione' => 'mt', 'duration_months' => 12]);
        $add('edison_mt_fisso', 'Edison Business MT Fisso', 'azienda', ['price' => 0.158, 'fixed_monthly' => 30], ['price' => 0.63, 'fixed_monthly' => 30], ['tensione' => 'mt', 'duration_months' => 24]);

        // Gli esempi della versione precedente (preventivo da consumo) non servono più.
        $old = Product::where('type', 'ENERGY')->whereIn('code', ['edison_luce_flex', 'edison_luce_fisso', 'edison_gas_flex', 'edison_gas_fisso'])->get();
        $company->products()->detach($old->pluck('id')->all());
        Product::whereIn('id', $old->pluck('id'))->update(['is_active' => false]);

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
