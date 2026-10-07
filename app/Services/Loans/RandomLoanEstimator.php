<?php

namespace App\Services\Loans;

use App\Models\LoanRequest;

/** Simulazione: valori casuali, in attesa del servizio di calcolo vero. */
class RandomLoanEstimator implements LoanEstimator
{
    public function estimate(LoanRequest $loan): array
    {
        $min = random_int(10, 100) * 100;

        return ['min' => $min, 'max' => $min + random_int(10, 200) * 100];
    }
}
