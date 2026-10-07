<?php

namespace App\Console\Commands;

use App\Services\Checks\CheckRegistry;
use Illuminate\Console\Command;

class SyncChecks extends Command
{
    protected $signature = 'checks:sync';

    protected $description = 'Aggiunge all\'elenco dei controlli le classi nuove trovate in app/Services/Checks';

    public function handle(CheckRegistry $registry): int
    {
        $added = $registry->sync();

        $this->info($added === 0 ? 'Nessun controllo nuovo.' : "Aggiunti {$added} controlli.");

        return self::SUCCESS;
    }
}
