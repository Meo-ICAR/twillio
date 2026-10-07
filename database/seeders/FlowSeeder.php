<?php

namespace Database\Seeders;

use App\Services\Flows\FlowImporter;
use Illuminate\Database\Seeder;

/** Importa i percorsi di conversazione dalla configurazione, senza toccare quelli già presenti. */
class FlowSeeder extends Seeder
{
    public function run(): void
    {
        app(FlowImporter::class)->import();
    }
}
