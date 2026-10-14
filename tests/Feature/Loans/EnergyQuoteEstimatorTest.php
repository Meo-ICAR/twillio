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

    public function test_sceglie_il_prodotto_piu_conveniente_e_calcola_il_risparmio(): void
    {
        $company = $this->company(
            ['fornitura' => 'luce', 'price' => 0.10, 'fixed_monthly' => 10],
            ['fornitura' => 'luce', 'price' => 0.12, 'fixed_monthly' => 0],
            ['fornitura' => 'gas', 'price' => 0.01, 'fixed_monthly' => 0],
        );

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => '3000', 'spesa_attuale' => '600']), $company);

        // P0: 3000*0.10 + 120 = 420; P1: 360. Vince P1 (e il gas è escluso).
        $this->assertSame('p1', $quote['product']->code);
        $this->assertEqualsWithDelta(360.0, $quote['annual_cost'], 0.01);
        $this->assertEqualsWithDelta(240.0, $quote['saving'], 0.01);
    }

    public function test_il_bonus_riduce_il_costo(): void
    {
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.80, 'fixed_monthly' => 5, 'bonus' => 50]);

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('gas', ['consumo' => '1000']), $company);

        $this->assertEqualsWithDelta(810.0, $quote['annual_cost'], 0.01); // 800 + 60 - 50
        $this->assertNull($quote['saving']);
    }

    public function test_senza_prodotti_adatti_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.8]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => '3000']), $company));
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

    public function test_consumo_non_valido_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'luce', 'price' => 0.1]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => 'boh']), $company));
    }
}
