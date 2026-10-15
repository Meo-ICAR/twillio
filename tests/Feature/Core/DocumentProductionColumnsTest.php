<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentProductionColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_company_produce_documenti_solo_con_flag_e_riferimenti_al_core(): void
    {
        $this->assertFalse(Company::create(['name' => 'A'])->producesDocuments());
        $this->assertFalse(Company::create(['name' => 'B', 'produce_documents' => true])->producesDocuments());
        $this->assertTrue(Company::create(['name' => 'C', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza'])->producesDocuments());
    }

    public function test_gli_stati_nuovi_hanno_un_nome_leggibile(): void
    {
        $this->assertSame('Trasmessa in istruttoria', LoanRequest::STATUSES['invio_istruttoria']);
        $this->assertSame('Da perfezionare', LoanRequest::STATUSES['perfezionare']);
        $this->assertArrayHasKey('in_attesa', LoanRequest::SIGNATURE_STATUSES);
    }

    public function test_la_pratica_ricorda_firma_e_invio(): void
    {
        $loan = LoanRequest::create(['code' => 'X-1', 'agent_wa_number' => '39333', 'product' => 'luce', 'status' => 'richiesta', 'answers' => [], 'signature_status' => 'in_attesa', 'signed_at' => now(), 'documents_sent_at' => now()]);

        $this->assertSame('in_attesa', $loan->fresh()->signature_status);
        $this->assertNotNull($loan->fresh()->signed_at);
    }
}
