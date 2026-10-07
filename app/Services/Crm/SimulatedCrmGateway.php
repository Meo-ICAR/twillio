<?php

namespace App\Services\Crm;

use App\Models\LoanRequest;

/** Simulazione in attesa dell'endpoint vero: risponde con il codice di `finanziamento.crm.simulated_status` (200 di default). */
class SimulatedCrmGateway implements CrmGateway
{
    public function submit(LoanRequest $loan, array $personal): int
    {
        return (int) config('finanziamento.crm.simulated_status', 200);
    }
}
