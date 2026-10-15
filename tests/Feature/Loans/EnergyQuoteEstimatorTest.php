<?php

namespace Tests\Feature\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\Product;
use App\Services\Loans\EnergyQuoteEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnergyQuoteEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function company(array ...$products): Company
    {
        $company = Company::create(['name' => 'GBD', 'type' => 'ENERGY']);
        foreach ($products as $i => $params) {
            $company->products()->attach(Product::create(['type' => 'ENERGY', 'name' => "P$i", 'code' => "p$i", 'params' => $params])->id);
        }

        return $company;
    }

    private function loan(string $product, array $answers): LoanRequest
    {
        return new LoanRequest(['product' => $product, 'answers' => $answers]);
    }

    public function test_ricava_il_consumo_dalla_spesa_e_sceglie_l_offerta_piu_conveniente(): void
    {
        config(['energy.quote.reference.privato.luce' => 0.25]);
        $company = $this->company(
            ['fornitura' => 'luce', 'price' => 0.10, 'fixed_monthly' => 10],
            ['fornitura' => 'luce', 'price' => 0.12, 'fixed_monthly' => 0],
            ['fornitura' => 'gas', 'price' => 0.01, 'fixed_monthly' => 0],
        );

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['profilo' => 'privato', 'spesa_mensile' => '50']), $company);

        // 50*12/0.25 = 2400 kWh. P0: 240 + 120 = 360; P1: 288. Vince P1 (il gas è escluso).
        $this->assertSame('p1', $quote['lines'][0]['product']->code);
        $this->assertEqualsWithDelta(2400.0, $quote['lines'][0]['consumption'], 0.1);
        $this->assertEqualsWithDelta(288.0, $quote['annual_cost'], 0.01);
        $this->assertEqualsWithDelta(24.0, $quote['monthly_cost'], 0.01);
        $this->assertEqualsWithDelta(312.0, $quote['saving'], 0.01);
    }

    public function test_il_bonus_riduce_il_costo(): void
    {
        config(['energy.quote.reference.privato.gas' => 1.0]);
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.80, 'fixed_monthly' => 5, 'bonus' => 50]);

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('gas', ['spesa_mensile' => '1000/12']), $company);
        $this->assertNull($quote); // testo non numerico

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('gas', ['spesa_mensile' => '100']), $company);

        $this->assertEqualsWithDelta(1200 * 0.80 + 60 - 50, $quote['annual_cost'], 0.01);
    }

    public function test_filtra_per_segmento_residenza_e_tensione(): void
    {
        $company = $this->company(
            ['fornitura' => 'luce', 'segmento' => 'privato', 'price' => 0.20],
            ['fornitura' => 'luce', 'segmento' => 'privato', 'residenza' => 'seconda', 'price' => 0.10],
            ['fornitura' => 'luce', 'segmento' => 'azienda', 'tensione' => 'bt', 'price' => 0.15],
            ['fornitura' => 'luce', 'segmento' => 'azienda', 'tensione' => 'mt', 'price' => 0.12],
        );
        $e = app(EnergyQuoteEstimator::class);
        $code = fn (array $answers) => $e->quote($this->loan('luce', $answers + ['spesa_mensile' => '100']), $company)['lines'][0]['product']->code;

        $this->assertSame('p0', $code(['profilo' => 'privato', 'residenza' => 'principale']));
        $this->assertSame('p1', $code(['profilo' => 'privato', 'residenza' => 'seconda']));
        $this->assertSame('p2', $code(['profilo' => 'azienda', 'tensione' => 'bt']));
        $this->assertSame('p3', $code(['profilo' => 'azienda', 'tensione' => 'mt']));
    }

    public function test_il_limite_di_consumo_esclude_l_offerta(): void
    {
        config(['energy.quote.reference.bt.luce' => 0.25]);
        $company = $this->company(
            ['fornitura' => 'luce', 'segmento' => 'azienda', 'tensione' => 'bt', 'price' => 0.10, 'max_kwh' => 5000],
            ['fornitura' => 'luce', 'segmento' => 'azienda', 'tensione' => 'bt', 'price' => 0.20],
        );
        $e = app(EnergyQuoteEstimator::class);
        $answers = ['profilo' => 'azienda', 'tensione' => 'bt'];

        // 100*12/0.25 = 4800 kWh: entrambe valide, vince la prima. 200 → 9600 kWh: la prima è fuori limite.
        $this->assertSame('p0', $e->quote($this->loan('luce', $answers + ['spesa_mensile' => '100']), $company)['lines'][0]['product']->code);
        $this->assertSame('p1', $e->quote($this->loan('luce', $answers + ['spesa_mensile' => '200']), $company)['lines'][0]['product']->code);
    }

    public function test_luce_e_gas_ripartiscono_la_spesa_e_sommano_le_offerte(): void
    {
        config(['energy.quote.reference.privato' => ['luce' => 0.25, 'gas' => 1.0], 'energy.quote.split' => ['luce' => 0.5, 'gas' => 0.5]]);
        $company = $this->company(['fornitura' => 'luce', 'price' => 0.20], ['fornitura' => 'gas', 'price' => 0.50]);

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('luce_gas', ['spesa_mensile' => '100']), $company);

        // Luce: 600/0.25 = 2400 kWh → 480. Gas: 600/1 = 600 Smc → 300.
        $this->assertCount(2, $quote['lines']);
        $this->assertEqualsWithDelta(780.0, $quote['annual_cost'], 0.01);
        $this->assertEqualsWithDelta(65.0, $quote['monthly_cost'], 0.01);
    }

    public function test_senza_prodotti_adatti_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.8]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['spesa_mensile' => '100']), $company));
        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce_gas', ['spesa_mensile' => '100']), $company));
    }

    public function test_i_numeri_italiani_si_leggono_correttamente(): void
    {
        $e = app(EnergyQuoteEstimator::class);

        $this->assertSame(3500.5, $e->parseNumber('3.500,5'));
        $this->assertSame(3500.5, $e->parseNumber('3500,5'));
        $this->assertSame(3500.5, $e->parseNumber('3500.5'));
        $this->assertSame(3500.0, $e->parseNumber('3500'));
        $this->assertSame(3500.0, $e->parseNumber('3.500'));
        $this->assertNull($e->parseNumber('molto'));
        $this->assertNull($e->parseNumber(''));
    }

    public function test_spesa_non_valida_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'luce', 'price' => 0.1]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['spesa_mensile' => 'boh']), $company));
        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['spesa_mensile' => '0']), $company));
    }
}
