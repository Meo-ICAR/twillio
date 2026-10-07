<?php

namespace App\Services\Loans;

use App\Models\LoanRequest;

/** Calcola l'importo minimo e massimo ottenibile per una richiesta. */
interface LoanEstimator
{
    /** @return array{min: int, max: int} importi in euro */
    public function estimate(LoanRequest $loan): array;
}
