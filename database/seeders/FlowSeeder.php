<?php

namespace Database\Seeders;

use App\Services\Checks\CheckRegistry;
use App\Services\Flows\FlowImporter;
use Illuminate\Database\Seeder;

/** Importa i percorsi di conversazione dalla configurazione, senza toccare quelli già presenti. */
class FlowSeeder extends Seeder
{
    public function run(): void
    {
        // Prima i controlli disponibili, poi i percorsi che li agganciano.
        app(CheckRegistry::class)->sync();
        app(FlowImporter::class)->import();
    }
}
