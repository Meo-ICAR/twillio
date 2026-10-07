<?php

namespace App\Console\Commands;

use App\Models\LoanRequest;
use Illuminate\Console\Command;

class PurgeUnperfected extends Command
{
    protected $signature = 'finanziamento:purge {--dry-run : Mostra cosa verrebbe cancellato senza cancellare}';

    protected $description = 'Cancella le pratiche non perfezionate oltre il termine di conservazione, con i loro file';

    public function handle(): int
    {
        $days = (int) config('privacy.retention_days');
        $expired = LoanRequest::where('status', '!=', 'perfezionata')
            ->where('created_at', '<', now()->subDays($days));

        $count = $expired->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} pratiche non perfezionate da oltre {$days} giorni (nessuna cancellazione: dry-run).");

            return self::SUCCESS;
        }

        // La cancellazione di una pratica chiude anche le sue conversazioni aperte (vedi LoanRequest::booted).
        $expired->get()->each(fn (LoanRequest $loan) => $loan->delete());

        $this->info("Cancellate {$count} pratiche non perfezionate da oltre {$days} giorni.");

        return self::SUCCESS;
    }
}
