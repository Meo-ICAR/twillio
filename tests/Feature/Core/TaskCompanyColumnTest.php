<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Schema;
use Unico\Core\Models\Task;

class TaskCompanyColumnTest extends CoreTestCase
{
    public function test_i_task_hanno_la_company_facoltativa(): void
    {
        $this->assertTrue(Schema::connection('core')->hasColumn('tasks', 'company_id'));
    }

    public function test_lo_scope_prende_i_task_della_company_e_quelli_comuni(): void
    {
        $a = $this->coreCompany();
        $b = $this->coreCompany('Altra');
        $common = Task::create(['name' => 'Comune '.uniqid(), 'taskable' => 'pratica']);
        $mine = Task::create(['name' => 'Mio '.uniqid(), 'taskable' => 'pratica', 'company_id' => $a->id]);
        $other = Task::create(['name' => 'Altrui '.uniqid(), 'taskable' => 'pratica', 'company_id' => $b->id]);

        $ids = Task::forCompany($a->id)->pluck('id')->all();

        $this->assertContains($common->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
