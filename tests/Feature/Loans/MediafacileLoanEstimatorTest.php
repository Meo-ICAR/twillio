<?php

namespace Tests\Feature\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\QuoteSimulation;
use App\Services\Loans\MediafacileLoanEstimator;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileLoanEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function xml(array $offers): string
    {
        $items = collect($offers)->map(fn ($o) => "<Offerta><Importo_erogato>{$o[0]}</Importo_erogato><Errore>{$o[1]}</Errore></Offerta>")->implode('');

        return "<?xml version=\"1.0\"?><Offerte>{$items}</Offerte>";
    }

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'quinto', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'quinto', 'importo' => 'imp_20k', 'durata' => 'm60', 'eta' => 'eta_50', 'lavoro' => 'dip_pub', 'contratto' => 'indet', 'anzianita' => 'anz_10', 'reddito' => 'red_2000'],
        ]);
    }

    private function company(array $override = []): Company
    {
        return Company::create($override + ['name' => 'Hassisto Srl', 'url_preventivatore' => 'https://crm.example.com/ws/offerte', 'preventivatore_passkey' => 'KEY']);
    }

    public function test_l_intervallo_va_dal_minimo_del_peggiore_al_massimo_del_migliore(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()
            ->push($this->xml([['15000,40', 2], ['14000,00', 2], ['99999,00', 1]]))   // migliore
            ->push($this->xml([['9000,00', 2], ['8000,60', 2], ['100,00', 1]]))],      // peggiore
        );

        $range = app(MediafacileLoanEstimator::class)->estimate($this->loan());

        $this->assertSame(['min' => 8001, 'max' => 15000], $range);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return ($q['Passkey'] ?? null) === 'KEY' && ($q['Data_nascita'] ?? null) === '01-01-1986' && ($q['Importo_rata'] ?? null) === '400,00';
        });
        $this->assertSame(['best', 'worst'], QuoteSimulation::orderBy('id')->pluck('scenario')->all());
        $this->assertSame(3, QuoteSimulation::where('scenario', 'best')->value('offers_count'));
        $this->assertEquals(14000, QuoteSimulation::where('scenario', 'best')->value('erogato_min'));
        $this->assertArrayNotHasKey('Passkey', QuoteSimulation::first()->request);
    }

    public function test_se_il_peggiore_supera_il_migliore_l_intervallo_si_ordina(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()->push($this->xml([['5000,00', 2]]))->push($this->xml([['7000,00', 2]]))]);

        $this->assertSame(['min' => 5000, 'max' => 7000], app(MediafacileLoanEstimator::class)->estimate($this->loan()));
    }

    public function test_senza_offerte_valide_in_uno_scenario_non_c_e_stima_ma_resta_la_traccia(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()->push($this->xml([['0', 1]]))]);

        try {
            app(MediafacileLoanEstimator::class)->estimate($this->loan());
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            $this->assertSame(1, QuoteSimulation::count());
            $this->assertNull(QuoteSimulation::first()->erogato_min);
        }
    }

    public function test_i_prodotti_non_gestiti_dal_driver_usano_il_simulatore_casuale(): void
    {
        Http::fake();
        $this->company();
        $loan = LoanRequest::create([
            'code' => 'FIN-2026-0002', 'agent_wa_number' => '393331112222', 'product' => 'finalizzato', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'finalizzato', 'importo' => 'imp_10k'],
        ]);

        $range = app(MediafacileLoanEstimator::class)->estimate($loan);

        $this->assertLessThan($range['max'], $range['min']);
        Http::assertNothingSent();
        $this->assertSame(0, QuoteSimulation::count());
    }

    public function test_senza_url_o_passkey_non_si_chiama_il_servizio(): void
    {
        Http::fake();
        $this->company(['preventivatore_passkey' => null]);

        try {
            app(MediafacileLoanEstimator::class)->estimate($this->loan());
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            Http::assertNothingSent();
        }
    }
}
