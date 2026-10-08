<?php

namespace Database\Seeders;

use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use Illuminate\Database\Seeder;

/** Liste valori della specifica Mediafacile 3.8 e ipotesi di mappatura/fasce, da tarare. Ripetibile. */
class QuoteCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Cessione', 'Cessione del quinto', 'quinto'],
            ['Delega', 'Delegazione di pagamento', null],
            ['Prestito', 'Prestito personale', 'personale'],
        ] as [$value, $label, $product]) {
            QuoteContractType::updateOrCreate(['value' => $value], ['label' => $label, 'product' => $product]);
        }

        $pensionati = ['Cessione', 'Prestito'];
        foreach ([
            ['Statale', null, null], ['Pubblico', null, null], ['Privato SPA', null, null], ['Privato Altra forma', null, null],
            ['Privato Small Business', null, null], ['Medico', null, null],
            ['Pensionato INPS', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Pensionato INPDAP', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Pensionato altri enti', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Postale', null, null], ['Ferroviere', null, null],
            ['Parapubblico', null, 'Comprende anche le categorie Municipalizzate e Parastatali'],
        ] as [$value, $contracts, $note]) {
            QuoteEmploymentType::updateOrCreate(['value' => $value], ['contracts' => $contracts, 'note' => $note]);
        }

        foreach (['Cessione' => range(24, 120, 12), 'Delega' => range(24, 120, 12), 'Prestito' => range(12, 120, 12)] as $contract => $months) {
            foreach ($months as $m) {
                QuoteDuration::updateOrCreate(['contract' => $contract, 'months' => $m]);
            }
        }

        // lavoro, ente_pensione, dimensione_azienda, Tipo_rapporto, priorità (vince la riga più specifica)
        QuoteEmploymentMap::query()->delete();
        foreach ([
            ['dip_pub', null, null, 'Pubblico', 0],
            ['dip_priv', null, null, 'Privato Altra forma', 0],
            ['dip_priv', null, 'oltre15', 'Privato SPA', 2],
            ['dip_priv', null, 'fino15', 'Privato Small Business', 2],
            ['pensionato', 'inps', null, 'Pensionato INPS', 1],
            ['pensionato', 'exinpdap', null, 'Pensionato INPDAP', 1],
            ['pensionato', 'altro', null, 'Pensionato altri enti', 1],
            ['autonomo', null, null, 'Privato Small Business', 0],
            ['altro', null, null, 'Privato Altra forma', 0],
        ] as [$lavoro, $ente, $dimensione, $rapporto, $priority]) {
            QuoteEmploymentMap::create(['lavoro' => $lavoro, 'ente_pensione' => $ente, 'dimensione_azienda' => $dimensione, 'tipo_rapporto' => $rapporto, 'priority' => $priority]);
        }

        foreach ([
            'eta' => ['eta_30' => [20, 30, '20 - 30 anni'], 'eta_40' => [30, 40, '30 - 40 anni'], 'eta_50' => [40, 50, '40 - 50 anni'], 'eta_60' => [50, 60, '50 - 60 anni'], 'eta_75' => [60, 75, '60 - 75 anni']],
            'anzianita' => ['anz_1' => [0, 1, 'Meno di 1 anno'], 'anz_3' => [1, 3, '1 - 3 anni'], 'anz_10' => [3, 10, '3 - 10 anni'], 'anz_20' => [10, 20, '10 - 20 anni'], 'anz_30' => [20, 30, '20 - 30 anni'], 'anz_40' => [30, 40, '30 - 40 anni']],
            'reddito' => ['red_1500' => [1000, 1500, '1.000 - 1.500 €'], 'red_2000' => [1500, 2000, '1.500 - 2.000 €'], 'red_3000' => [2000, 3000, '2.000 - 3.000 €'], 'red_oltre' => [3000, 5000, '3.000 - 5.000 €']],
            'importo' => ['imp_5k' => [1000, 5000, '1.000 - 5.000 €'], 'imp_10k' => [5000, 10000, '5.000 - 10.000 €'], 'imp_20k' => [10000, 20000, '10.000 - 20.000 €'], 'imp_35k' => [20000, 35000, '20.000 - 35.000 €'], 'imp_oltre' => [35000, 50000, '35.000 - 50.000 €']],
        ] as $dimension => $bands) {
            foreach ($bands as $code => [$low, $high, $label]) {
                QuoteBandBound::updateOrCreate(['dimension' => $dimension, 'code' => $code], ['label' => $label, 'low' => $low, 'high' => $high]);
            }
        }
    }
}
