<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\DB;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PraticaStato;
use Unico\Core\Models\Task;

class GbdCoreDataSeederTest extends CoreTestCase
{
    private int $coreId;

    private int $privacyTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        // L'agente sta nel core con company_id = id locale: serve una company core con quell'id; e la company GBD del core.
        DB::connection('core')->table('companies')->insertOrIgnore(['id' => 1, 'name' => 'Specchio locale', 'created_at' => now(), 'updated_at' => now()]);
        $this->coreId = \Unico\Core\Models\Company::create(['name' => 'GBD Energy'])->id;
        $this->privacyTypeId = DocumentType::create(['name' => 'NUOVA Infomativa privacy 2025 - Editato '.uniqid(), 'code' => 'PRIV'.uniqid()])->id;
    }

    public function test_il_seeder_crea_stati_task_e_requisiti_di_gbd_senza_duplicare(): void
    {
        $this->seed(GbdEnergySeeder::class);
        $this->seed(GbdEnergySeeder::class);

        foreach (['INVIO IN ISTRUTTORIA', 'ALLA FIRMA', 'PERFEZIONARE'] as $name) {
            $this->assertSame(1, PraticaStato::where('tipo_finanziamento', 'Utenza')->where('name', $name)->count(), $name);
        }
        $task = Task::where('company_id', $this->coreId)->where('trigger_subvalue', 'ALLA FIRMA')->where('trigger_value', 'Utenza')->first();
        $this->assertNotNull($task);
        $this->assertSame(1, Task::where('company_id', $this->coreId)->where('trigger_subvalue', 'ALLA FIRMA')->count());
        $this->assertSame(1, Task::where('company_id', $this->coreId)->where('trigger_subvalue', 'Inserita')->count());
        $this->assertSame(1, DocumentRequirement::where('task_id', $task->id)->where('document_type_id', $this->privacyTypeId)->count());
    }

    public function test_il_seeder_identifica_il_core_su_gbd_ma_non_attiva_la_produzione(): void
    {
        $this->seed(GbdEnergySeeder::class);

        $gbd = Company::where('name', 'GBD Energy')->first();
        $this->assertSame($this->coreId, (int) $gbd->core_company_id);
        $this->assertSame('Utenza', $gbd->core_tipo_prodotto);
        $this->assertFalse($gbd->produce_documents);
    }

    public function test_senza_la_company_gbd_nel_core_il_seeder_non_scrive_nel_core_e_non_si_rompe(): void
    {
        \Unico\Core\Models\Company::where('id', $this->coreId)->delete();

        $this->seed(GbdEnergySeeder::class);

        $this->assertNull(Company::where('name', 'GBD Energy')->value('core_company_id'));
        $this->assertSame(0, Task::where('name', 'like', '%(GBD)')->count());
    }
}
