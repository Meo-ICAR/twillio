<?php

namespace App\Console\Commands;

use App\Services\Conversation\FlowGraph;
use Illuminate\Console\Command;

class FinanziamentoGraph extends Command
{
    protected $signature = 'finanziamento:graph {--path=docs : Cartella di destinazione (relativa al progetto o assoluta)}';

    protected $description = 'Genera il grafo delle domande del bot da config/finanziamento.php';

    public function handle(FlowGraph $graph): int
    {
        $path = $this->option('path');
        $dir = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (array_keys(config('finanziamento.flows')) as $flow) {
            file_put_contents("{$dir}/finanziamento-{$flow}.mmd", $graph->mermaid($flow));
        }
        file_put_contents("{$dir}/finanziamento-grafo.html", $graph->html());

        $this->info("Grafo scritto in {$dir}/finanziamento-grafo.html");

        return self::SUCCESS;
    }
}
