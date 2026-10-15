<?php

namespace App\Console\Commands;

use App\Models\LoanRequest;
use App\Services\Documents\LoanSignatureFinalizer;
use Illuminate\Console\Command;

class FinalizeSignedLoans extends Command
{
    protected $signature = 'signatures:finalize';

    protected $description = 'Chiude le pratiche con tutte le firme complete che il listener non ha chiuso (firme quasi simultanee, webhook perso)';

    public function handle(LoanSignatureFinalizer $finalizer): int
    {
        $closed = LoanRequest::where('signature_status', 'in_attesa')->whereNull('signed_at')->get()
            ->filter(fn (LoanRequest $loan) => $finalizer->finalize($loan))->count();

        $this->info("Pratiche chiuse: {$closed}.");

        return self::SUCCESS;
    }
}
