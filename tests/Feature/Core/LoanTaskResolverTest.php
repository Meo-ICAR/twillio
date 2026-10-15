<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Services\Documents\LoanTaskResolver;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\Task;

class LoanTaskResolverTest extends CoreTestCase
{
    private int $coreId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreId = $this->coreCompany()->id;
    }

    private function company(): Company
    {
        return Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $this->coreId, 'core_tipo_prodotto' => 'Utenza']);
    }

    private function task(string $stato, ?int $companyId, string $tipo = 'Utenza', bool $active = true): Task
    {
        return Task::create([
            'name' => "$tipo · $stato ".uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => $tipo,
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => $stato, 'company_id' => $companyId, 'is_active' => $active,
        ]);
    }

    private function require(Task $task, PdfModule $module, int $order = 1): void
    {
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $module->document_type_id, 'is_required' => true, 'sort_order' => $order]);
    }

    public function test_restituisce_i_moduli_dei_task_giusti_in_ordine(): void
    {
        $a = $this->coreModule(null, 'Informativa');
        $b = $this->coreModule(null, 'Contratto');
        $task = $this->task('ALLA FIRMA', $this->coreId);
        $this->require($task, $b, 2);
        $this->require($task, $a, 1);

        $names = app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->pluck('name')->all();

        $this->assertSame(['Informativa', 'Contratto'], $names);
    }

    public function test_esclude_altre_company_altri_tipi_altri_stati_e_task_disattivi(): void
    {
        $m = $this->coreModule(null, 'Escluso');
        $other = $this->coreCompany('Altra')->id;
        foreach ([$this->task('ALLA FIRMA', $other), $this->task('ALLA FIRMA', $this->coreId, 'Cessione'), $this->task('Inserita', $this->coreId), $this->task('ALLA FIRMA', $this->coreId, 'Utenza', false)] as $t) {
            $this->require($t, $m);
        }

        $this->assertTrue(app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->isEmpty());
    }

    public function test_un_task_senza_company_vale_per_tutte(): void
    {
        $m = $this->coreModule(null, 'Comune');
        $this->require($this->task('ALLA FIRMA', null), $m);

        $this->assertSame(['Comune'], app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->pluck('name')->all());
    }

    public function test_un_tipo_documento_senza_modulo_o_con_modulo_disattivo_e_ignorato(): void
    {
        $type = DocumentType::create(['name' => 'Solo caricamento', 'code' => 'T'.uniqid()]);
        $off = $this->coreModule(null, 'Spento');
        $off->update(['is_active' => false]);
        $task = $this->task('ALLA FIRMA', $this->coreId);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $type->id, 'is_required' => true, 'sort_order' => 1]);
        $this->require($task, $off, 2);

        $this->assertTrue(app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->isEmpty());
    }

    public function test_una_company_che_non_produce_documenti_non_ha_moduli(): void
    {
        $this->require($this->task('ALLA FIRMA', null), $this->coreModule());

        $this->assertTrue(app(LoanTaskResolver::class)->modules(Company::create(['name' => 'X']), 'ALLA FIRMA')->isEmpty());
    }

    public function test_un_modulo_ripetuto_in_piu_task_compare_una_volta(): void
    {
        $m = $this->coreModule(null, 'Doppio');
        $this->require($this->task('ALLA FIRMA', null), $m);
        $this->require($this->task('ALLA FIRMA', $this->coreId), $m);

        $this->assertCount(1, app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA'));
    }
}
