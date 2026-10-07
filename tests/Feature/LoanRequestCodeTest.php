<?php

namespace Tests\Feature;

use App\Models\LoanRequest;
use App\Services\Conversation\LoanRequestCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanRequestCodeTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $code): void
    {
        LoanRequest::create(['code' => $code, 'agent_wa_number' => '39', 'product' => 'mutuo', 'answers' => []]);
    }

    public function test_il_primo_codice_dell_anno_e_0001(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');

        $this->assertSame('FIN-2026-0001', LoanRequestCode::next());
    }

    public function test_il_codice_e_progressivo_nell_anno(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->make('FIN-2026-0001');
        $this->make('FIN-2026-0009');

        $this->assertSame('FIN-2026-0010', LoanRequestCode::next());
    }

    public function test_si_riparte_da_1_a_nuovo_anno(): void
    {
        Carbon::setTestNow('2027-01-02 10:00:00');
        $this->make('FIN-2026-0042');

        $this->assertSame('FIN-2027-0001', LoanRequestCode::next());
    }
}
