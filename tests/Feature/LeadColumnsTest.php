<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_passkey_del_crm_si_salva_cifrata(): void
    {
        $company = Company::create(['name' => 'H', 'istruttoria_passkey' => 'SEGRETA']);

        $this->assertSame('SEGRETA', $company->fresh()->istruttoria_passkey);
        $this->assertNotSame('SEGRETA', DB::table('companies')->where('id', $company->id)->value('istruttoria_passkey'));
    }

    public function test_la_pratica_ricorda_il_lead_e_l_archiviazione(): void
    {
        $loan = LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => '39333', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $loan->update(['crm_lead_id' => 'ABC-1', 'documents_archived_at' => now()]);

        $this->assertSame('ABC-1', $loan->fresh()->crm_lead_id);
        $this->assertNotNull($loan->fresh()->documents_archived_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $loan->fresh()->documents_archived_at);
    }
}
