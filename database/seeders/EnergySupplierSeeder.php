<?php

namespace Database\Seeders;

use App\Models\EnergySupplier;
use App\Services\Energy\EnergySupplierMatcher;
use Illuminate\Database\Seeder;

/** Importa l'elenco dei venditori ARERA da database/data/arera-venditori.csv (ripetibile: aggiorna per partita IVA). */
class EnergySupplierSeeder extends Seeder
{
    public function run(): void
    {
        $handle = fopen(database_path('data/arera-venditori.csv'), 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $r = array_combine($header, array_pad($row, count($header), ''));
            EnergySupplier::updateOrCreate(['vat' => $r['vat']], [
                'name' => $r['name'],
                'name_key' => EnergySupplierMatcher::normalize($r['name']),
                'arera_id' => $r['arera_id'] !== '' ? (int) $r['arera_id'] : null,
                'sells_electricity' => $r['luce'] === '1',
                'sells_gas' => $r['gas'] === '1',
                'website' => $r['website'] !== '' ? $r['website'] : null,
            ]);
        }

        fclose($handle);
    }
}
