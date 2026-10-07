<?php

namespace App\Console\Commands;

use App\Services\Flows\FlowImporter;
use Illuminate\Console\Command;

class ImportFlows extends Command
{
    protected $signature = 'flows:import {--force : Ripristina anche i percorsi già presenti, perdendo le modifiche fatte dal pannello}';

    protected $description = 'Importa nelle tabelle i percorsi di conversazione scritti in config/finanziamento.php';

    public function handle(FlowImporter $importer): int
    {
        $count = $importer->import((bool) $this->option('force'));

        $this->info($count === 0 ? 'Nessun percorso importato: ci sono già (usa --force per ripristinarli).' : "Importati {$count} percorsi.");

        return self::SUCCESS;
    }
}
