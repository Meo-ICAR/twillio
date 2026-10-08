<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowNodeJump;
use App\Models\FlowNodeOption;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\User;
use Database\Seeders\FlowSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultitenantPreparationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<class-string<Model>,string> */
    private function tenantModels(): array
    {
        return [
            User::class => 'company_id', Fornitore::class => 'tenant_company_id', LoanRequest::class => 'company_id',
            Attachment::class => 'company_id', Conversation::class => 'company_id', Flow::class => 'company_id',
            FlowNode::class => 'company_id', FlowNodeOption::class => 'company_id', FlowNodeJump::class => 'company_id',
        ];
    }

    public function test_le_tabelle_indicate_hanno_la_company_facoltativa(): void
    {
        foreach ($this->tenantModels() as $model => $column) {
            $table = (new $model)->getTable();
            $this->assertTrue(Schema::hasColumn($table, $column), "$table.$column");
            $this->assertSame($column, $model::companyColumn());
        }
    }

    public function test_i_check_non_hanno_la_company(): void
    {
        $this->assertFalse(Schema::hasColumn('flow_checks', 'company_id'));
    }

    public function test_fornitori_conserva_il_company_id_dell_anagrafica_madre(): void
    {
        $uuid = '5c044917-15b3-4471-90c9-38061fcca754';
        $f = Fornitore::create(['name' => 'X', 'company_id' => $uuid]);

        $this->assertSame($uuid, $f->fresh()->company_id);
        $this->assertNull($f->fresh()->tenant_company_id);
    }

    public function test_il_tenant_non_e_attivo_i_record_nascono_senza_company_e_si_vedono_tutti(): void
    {
        Company::create(['name' => 'A']);
        Company::create(['name' => 'B']);
        $loan = LoanRequest::create(['code' => 'FIN-1', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $conv = Conversation::create(['wa_number' => '39', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);

        $this->assertNull($loan->fresh()->company_id);
        $this->assertNull($conv->fresh()->company_id);
        $this->assertNull($loan->company);
        $this->assertSame(1, LoanRequest::count());
    }

    public function test_la_relazione_company_funziona_per_ogni_modello(): void
    {
        $company = Company::create(['name' => 'A']);
        $this->seed(FlowSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-1', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $records = [
            $loan, Fornitore::create(['name' => 'F']), User::factory()->create(), Flow::first(), FlowNode::first(), FlowNodeOption::first(), FlowNodeJump::first(),
            Conversation::create(['wa_number' => '39', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]),
            Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'reddito', 'path' => 'x', 'mime' => 'image/jpeg', 'received_at' => now()]),
        ];

        foreach ($records as $record) {
            $record->forceFill([$record::companyColumn() => $company->id])->save();
            $this->assertTrue($company->is($record->fresh()->company), $record::class);
        }
    }

    public function test_eliminando_la_company_i_record_restano_senza_company(): void
    {
        $company = Company::create(['name' => 'A']);
        $loan = LoanRequest::create(['code' => 'FIN-1', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $loan->forceFill(['company_id' => $company->id])->save();

        $company->delete();

        $this->assertNull($loan->fresh()->company_id);
    }
}
