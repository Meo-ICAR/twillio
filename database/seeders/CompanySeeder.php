<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

/** Azienda Titolare fittizia per la demo: i dati reali si inseriscono dal pannello (Azienda). */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        if (Company::exists()) {
            return;
        }

        Company::create([
            'name' => 'Azienda Demo Srl',
            'address' => 'Via dei Campioni 1, 00100 Roma',
            'email' => 'privacy@azienda-demo.example',
            'dpo_email' => null,
            'retention_perfected' => 'Per il tempo necessario alla gestione della pratica e comunque per i termini previsti dalla normativa applicabile all\'attività di mediazione creditizia.',
        ]);
    }
}
