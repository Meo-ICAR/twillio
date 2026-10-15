<?php

namespace App\Services\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\Product;

/**
 * Preventivatore energia sul modello dell'IVR: dalla spesa mensile ricava un consumo annuo (con un prezzo di riferimento), poi tra i
 * prodotti ENERGY attivi della company sceglie per ogni fornitura il più conveniente adatto a profilo, residenza o tensione e consumo.
 */
class EnergyQuoteEstimator
{
    /** @return array{lines: list<array{fornitura: string, product: Product, consumption: float, annual_cost: float}>, annual_cost: float, monthly_cost: float, saving: float}|null */
    public function quote(LoanRequest $loan, Company $company): ?array
    {
        $answers = $loan->answers ?? [];
        $spend = $this->parseNumber($answers['spesa_mensile'] ?? null);
        if ($spend === null || $spend <= 0) {
            return null;
        }

        $business = ($answers['profilo'] ?? 'privato') === 'azienda';
        $tension = $business ? (($answers['tensione'] ?? 'bt') === 'mt' ? 'mt' : 'bt') : null;
        $forniture = $loan->product === 'luce_gas' ? ['luce', 'gas'] : [(string) $loan->product];
        $products = $company->products()->active()->where('products.type', 'ENERGY')->get();

        $lines = [];
        foreach ($forniture as $fornitura) {
            $reference = $this->parseNumber(config('energy.quote.reference.'.($tension ?? 'privato').'.'.$fornitura));
            if ($reference === null || $reference <= 0) {
                return null;
            }
            $share = count($forniture) > 1 ? (float) config('energy.quote.split.'.$fornitura, 0.5) : 1.0;
            $consumption = $spend * 12 * $share / $reference;

            $best = $products
                ->filter(fn (Product $p) => $this->fits($p, $fornitura, $business ? 'azienda' : 'privato', $tension, $answers['residenza'] ?? null, $consumption))
                ->map(fn (Product $p) => ['fornitura' => $fornitura, 'product' => $p, 'consumption' => round($consumption, 1), 'annual_cost' => $this->annualCost($p, $consumption)])
                ->sortBy('annual_cost')->first();
            if ($best === null) {
                return null;
            }
            $lines[] = $best;
        }

        $annual = round(array_sum(array_column($lines, 'annual_cost')), 2);

        return ['lines' => $lines, 'annual_cost' => $annual, 'monthly_cost' => round($annual / 12, 2), 'saving' => round($spend * 12 - $annual, 2)];
    }

    /** Il prodotto serve questa fornitura, questo profilo (e residenza o tensione) e regge il consumo stimato? */
    private function fits(Product $product, string $fornitura, string $segment, ?string $tension, ?string $residence, float $consumption): bool
    {
        $p = $product->params ?? [];
        if (($p['fornitura'] ?? null) !== $fornitura || ($p['segmento'] ?? 'privato') !== $segment || $this->parseNumber($p['price'] ?? null) === null) {
            return false;
        }
        if ($segment === 'privato' && isset($p['residenza']) && $p['residenza'] !== $residence) {
            return false;
        }
        if ($segment === 'azienda' && ($p['tensione'] ?? 'bt') !== $tension) {
            return false;
        }
        $max = $this->parseNumber($p[$fornitura === 'luce' ? 'max_kwh' : 'max_smc'] ?? null);

        return $max === null || $consumption <= $max;
    }

    private function annualCost(Product $product, float $consumption): float
    {
        $p = $product->params;
        $number = fn (string $key): float => $this->parseNumber($p[$key] ?? null) ?? 0.0;

        return round($consumption * $number('price') + 12 * $number('fixed_monthly') - $number('bonus'), 2);
    }

    /** «3.500,5», «3.500», «3500,5», «3500.5» e «3500» → float; testo non numerico → null. */
    public function parseNumber(mixed $value): ?float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        // Il punto è separatore delle migliaia se c'è la virgola dei decimali o se i gruppi sono di tre cifre.
        if (str_contains($text, ',') || preg_match('/^\d{1,3}(\.\d{3})+$/', $text)) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
