<?php

namespace App\Console\Commands;

use App\Services\Flows\FlowConfigExporter;
use Illuminate\Console\Command;

class ExportFlows extends Command
{
    protected $signature = 'flows:export
        {--stdout : Stampa il file a video}
        {--path= : Scrive il file in questo percorso}
        {--to-config : Sostituisce config/finanziamento.php (con una copia di sicurezza accanto)}
        {--config-path= : File di configurazione da sostituire con --to-config (default config/finanziamento.php)}';

    protected $description = 'Ricrea dalle tabelle il file di configurazione dei percorsi di conversazione';

    public function handle(FlowConfigExporter $exporter): int
    {
        $source = $exporter->export();

        if ($this->option('stdout')) {
            $this->output->write($source);

            return self::SUCCESS;
        }

        if ($this->option('to-config')) {
            $target = $this->option('config-path') ?: config_path('finanziamento.php');

            if (is_file($target)) {
                $backup = $target.'.bak-'.date('Ymd-His');
                copy($target, $backup);
                $this->line("Copia di sicurezza: {$backup}");
            }
            file_put_contents($target, $source);
            $this->callSilently('config:clear');
            $this->info("Configurazione sostituita: {$target}");

            return self::SUCCESS;
        }

        $path = $this->option('path') ?: storage_path('app/exports/finanziamento-'.date('Ymd-His').'.php');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $source);
        $this->info("File scritto: {$path}");

        return self::SUCCESS;
    }
}
