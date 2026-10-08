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

    public function test_il_codice_e_sigla_mese_giorno_ora_e_minuti(): void
    {
        Carbon::setTestNow('2026-10-07 09:05:00');

        $this->assertSame('PM-1007-0905', LoanRequestCode::next(false, 'pm'));
    }

    public function test_senza_sigla_vale_seg_e_le_prove_hanno_il_prefisso_tst(): void
    {
        Carbon::setTestNow('2026-01-02 23:59:00');

        $this->assertSame('SEG-0102-2359', LoanRequestCode::next());
        $this->assertSame('TST-PM-0102-2359', LoanRequestCode::next(true, 'PM'));
    }

    public function test_due_preventivi_nello_stesso_minuto_non_si_scontrano(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->make('PM-1007-1000');
        $this->assertSame('PM-1007-1000A', LoanRequestCode::next(false, 'PM'));

        $this->make('PM-1007-1000A');
        $this->assertSame('PM-1007-1000B', LoanRequestCode::next(false, 'PM'));
        $this->assertSame('MR-1007-1000', LoanRequestCode::next(false, 'MR'), 'un altro produttore non è toccato');
    }

    public function test_cambia_con_il_passare_dei_minuti(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->make('PM-1007-1000');
        Carbon::setTestNow('2026-10-07 10:01:00');

        $this->assertSame('PM-1007-1001', LoanRequestCode::next(false, 'PM'));
    }
}
