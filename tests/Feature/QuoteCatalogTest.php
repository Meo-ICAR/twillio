<?php

namespace Tests\Feature;

use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_i_tipi_di_contratto_sono_quelli_del_servizio(): void
    {
        $this->assertSame(['Cessione', 'Delega', 'Prestito'], QuoteContractType::orderBy('value')->pluck('value')->all());
        $this->assertSame('quinto', QuoteContractType::where('value', 'Cessione')->value('product'));
        $this->assertSame('personale', QuoteContractType::where('value', 'Prestito')->value('product'));
    }

    public function test_i_tipi_di_rapporto_e_i_pensionati_solo_con_cessione_e_prestito(): void
    {
        $this->assertSame(12, QuoteEmploymentType::count());
        $this->assertSame(['Cessione', 'Prestito'], QuoteEmploymentType::where('value', 'Pensionato INPS')->first()->contracts);
        $this->assertNull(QuoteEmploymentType::where('value', 'Statale')->first()->contracts);
    }

    public function test_le_durate_ammesse_per_contratto(): void
    {
        $this->assertSame(9, QuoteDuration::where('contract', 'Cessione')->count());
        $this->assertSame(9, QuoteDuration::where('contract', 'Delega')->count());
        $this->assertSame(10, QuoteDuration::where('contract', 'Prestito')->count());
        $this->assertTrue(QuoteDuration::where('contract', 'Prestito')->where('months', 12)->exists());
        $this->assertFalse(QuoteDuration::where('contract', 'Cessione')->where('months', 12)->exists());
    }

    public function test_gli_estremi_delle_fasce_hanno_anche_l_etichetta_per_le_domande(): void
    {
        $this->assertSame([40, 50], $this->bounds('eta', 'eta_50'));
        $this->assertSame([30, 40], $this->bounds('anzianita', 'anz_40'));
        $this->assertSame([1000, 1500], $this->bounds('reddito', 'red_1500'));
        $this->assertSame([35000, 50000], $this->bounds('importo', 'imp_oltre'));
        $this->assertSame('40 - 50 anni', QuoteBandBound::where('code', 'eta_50')->value('label'));
        $this->assertSame('35.000 - 50.000 €', QuoteBandBound::where('code', 'imp_oltre')->value('label'));
    }

    public function test_la_mappatura_delle_risposte_in_tipo_rapporto(): void
    {
        $this->assertSame('Pubblico', QuoteEmploymentMap::where('lavoro', 'dip_pub')->value('tipo_rapporto'));
        $this->assertSame('Pensionato INPS', QuoteEmploymentMap::where('lavoro', 'pensionato')->where('ente_pensione', 'inps')->value('tipo_rapporto'));
        $this->assertSame('Privato SPA', QuoteEmploymentMap::where('lavoro', 'dip_priv')->where('dimensione_azienda', 'oltre15')->value('tipo_rapporto'));
    }

    /** @return array{0:int,1:int} */
    private function bounds(string $dimension, string $code): array
    {
        $b = QuoteBandBound::where('dimension', $dimension)->where('code', $code)->firstOrFail();

        return [(int) $b->low, (int) $b->high];
    }
}
