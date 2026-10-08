<?php

namespace Tests\Feature\Loans;

use App\Models\LoanRequest;
use App\Models\QuoteDuration;
use App\Services\Loans\Mediafacile\ScenarioBuilder;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScenarioBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loan(array $answers): LoanRequest
    {
        return new LoanRequest(['product' => $answers['prodotto'], 'answers' => $answers]);
    }

    private function quinto(array $override = []): LoanRequest
    {
        return $this->loan($override + [
            'prodotto' => 'quinto', 'importo' => 'imp_20k', 'durata' => 'm60', 'eta' => 'eta_50', 'sesso' => 'sesso_f',
            'lavoro' => 'dip_pub', 'contratto' => 'indet', 'anzianita' => 'anz_10', 'reddito' => 'red_2000',
        ]);
    }

    public function test_cessione_migliore_e_peggiore(): void
    {
        $s = (new ScenarioBuilder)->build($this->quinto());

        $this->assertSame([
            'Data_nascita' => '01-01-1986', 'Data_assunzione' => '01-01-2016', 'Data_decorrenza' => '12-08-2026', 'Sesso' => 'F',
            'Tipo_contratto' => 'Cessione', 'Tipo_rapporto' => 'Pubblico', 'Durata' => '60', 'Importo_rata' => '400,00', 'Rinnovo' => 'NO',
        ], $s['best']);
        $this->assertSame('01-01-1976', $s['worst']['Data_nascita']);
        $this->assertSame('01-01-2023', $s['worst']['Data_assunzione']);
        $this->assertSame('300,00', $s['worst']['Importo_rata']);
    }

    public function test_prestito_usa_importo_e_reddito_senza_decorrenza_e_il_sesso_di_ripiego(): void
    {
        $s = (new ScenarioBuilder)->build($this->loan([
            'prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'eta' => 'eta_40',
            'lavoro' => 'dip_priv', 'contratto' => 'det', 'anzianita' => 'anz_3', 'reddito' => 'red_3000',
        ]));

        $this->assertSame('Prestito', $s['best']['Tipo_contratto']);
        $this->assertSame('Privato Altra forma', $s['best']['Tipo_rapporto']);
        $this->assertSame('M', $s['best']['Sesso']);
        $this->assertSame('36', $s['best']['Durata']);
        $this->assertSame('10000,00', $s['best']['Importo_richiesto']);
        $this->assertSame('3000,00', $s['best']['Reddito_richiedenti']);
        $this->assertSame('5000,00', $s['worst']['Importo_richiesto']);
        $this->assertSame('2000,00', $s['worst']['Reddito_richiedenti']);
        $this->assertArrayNotHasKey('Data_decorrenza', $s['best']);
        $this->assertArrayNotHasKey('Importo_rata', $s['best']);
    }

    public function test_il_rapporto_dipende_dalla_dimensione_dell_azienda(): void
    {
        $spa = (new ScenarioBuilder)->build($this->quinto(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'oltre15']));
        $small = (new ScenarioBuilder)->build($this->quinto(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'fino15']));

        $this->assertSame('Privato SPA', $spa['best']['Tipo_rapporto']);
        $this->assertSame('Privato Small Business', $small['best']['Tipo_rapporto']);
    }

    public function test_il_pensionato_usa_la_pensione_e_l_anzianita_di_ripiego(): void
    {
        $s = (new ScenarioBuilder)->build($this->loan([
            'prodotto' => 'personale', 'importo' => 'imp_5k', 'durata' => 'm24', 'eta' => 'eta_75',
            'lavoro' => 'pensionato', 'ente_pensione' => 'inps', 'pensione_netta' => 'red_1500',
        ]));

        $this->assertSame('Pensionato INPS', $s['best']['Tipo_rapporto']);
        $this->assertSame('1500,00', $s['best']['Reddito_richiedenti']);
        $this->assertSame('01-01-2006', $s['best']['Data_assunzione'], 'nato nel 1966, 20 anni di anzianità');
        $this->assertSame('01-01-2006', $s['worst']['Data_assunzione'], 'nato nel 1951: stesso ripiego di 20 anni');
    }

    public function test_l_assunzione_non_precede_i_diciotto_anni(): void
    {
        $s = (new ScenarioBuilder)->build($this->quinto(['eta' => 'eta_30', 'anzianita' => 'anz_40']));

        $this->assertSame('01-01-2006', $s['best']['Data_nascita']);
        $this->assertSame('01-01-2024', $s['best']['Data_assunzione'], 'non prima dei 18 anni');
    }

    public function test_una_durata_non_ammessa_diventa_la_piu_vicina_a_parita_la_piu_bassa(): void
    {
        QuoteDuration::where('contract', 'Cessione')->where('months', 60)->delete();

        $s = (new ScenarioBuilder)->build($this->quinto());

        $this->assertSame('48', $s['best']['Durata']);
    }

    #[DataProvider('nonSimulabili')]
    public function test_le_richieste_non_simulabili_lanciano_quote_unavailable(array $override): void
    {
        $this->expectException(QuoteUnavailable::class);

        (new ScenarioBuilder)->build($this->quinto($override));
    }

    public static function nonSimulabili(): array
    {
        return [
            'finalizzato' => [['prodotto' => 'finalizzato']],
            'fascia di reddito vecchia' => [['reddito' => 'red_1000']],
            'fascia di anzianità vecchia' => [['anzianita' => 'anz_oltre']],
            'senza età' => [['eta' => null]],
            'senza durata' => [['durata' => null]],
            'senza reddito (lavoro altro)' => [['lavoro' => 'altro', 'reddito' => null]],
        ];
    }
}
