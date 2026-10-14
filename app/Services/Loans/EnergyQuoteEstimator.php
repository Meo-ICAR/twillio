<?php

namespace App\Services\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\Product;

/** Preventivatore energia: tra i prodotti ENERGY attivi della company consiglia il più conveniente per i consumi indicati. */
class EnergyQuoteEstimator
{
    /** @return array{product: Product, annual_cost: float, saving: ?float}|null */
    public function quote(LoanRequest $loan, Company $company): ?array
    {
        $answers = $loan->answers ?? [];
        $consumption = $this->parseNumber($answers['consumo'] ?? null);
        if ($consumption === null || $consumption <= 0) {
            return null;
        }

        $best = $company->products()->active()->where('products.type', 'ENERGY')->get()
            ->filter(fn (Product $p) => ($p->params['fornitura'] ?? null) === $loan->product && isset($p->params['price']))
            ->map(fn (Product $p) => ['product' => $p, 'annual_cost' => $this->annualCost($p, $consumption)])
            ->sortBy('annual_cost')->first();

        if ($best === null) {
            return null;
        }

        $current = $this->parseNumber($answers['spesa_attuale'] ?? null);

        return $best + ['saving' => $current !== null && $current > 0 ? round($current - $best['annual_cost'], 2) : null];
    }

    private function annualCost(Product $product, float $consumption): float
    {
        $p = $product->params;

        return round($consumption * (float) $p['price'] + 12 * (float) ($p['fixed_monthly'] ?? 0) - (float) ($p['bonus'] ?? 0), 2);
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
