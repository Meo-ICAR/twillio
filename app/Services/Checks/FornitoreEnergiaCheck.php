<?php

namespace App\Services\Checks;

use App\Services\Energy\EnergySupplierMatcher;

/**
 * Riconosce il fornitore di energia scritto dall'agente tra i venditori ARERA e ne ricava ragione sociale e partita IVA.
 * «altro» lascia proseguire senza dati ricavati (fornitori di tutela o non in elenco).
 */
class FornitoreEnergiaCheck implements NodeCheck
{
    public function __construct(private EnergySupplierMatcher $matcher) {}

    public function label(): string
    {
        return 'Fornitore di energia';
    }

    public function description(): string
    {
        return 'Cerca il fornitore tra i venditori di luce e gas dell\'elenco ARERA e ricava ragione sociale e partita IVA; «altro» permette di proseguire.';
    }

    public function derives(): array
    {
        return ['fornitore_attuale_nome', 'fornitore_attuale_piva'];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        $result = $this->matcher->match($value);

        return match ($result['status']) {
            'other' => true,
            'found' => $this->accept($result['supplier'], $ctx),
            'many' => $ctx->fail("Ho trovato più venditori: scrivi il nome completo come in bolletta.\n• ".collect($result['candidates'])->pluck('name')->implode("\n• ")),
            default => $ctx->fail('Non trovo questo fornitore tra i venditori di luce e gas. Controlla il nome in bolletta e riprova, oppure scrivi «altro» se non è in elenco (per esempio un fornitore di tutela).'),
        };
    }

    private function accept($supplier, CheckContext $ctx): bool
    {
        $ctx->set('fornitore_attuale_nome', $supplier->name);
        $ctx->set('fornitore_attuale_piva', $supplier->vat);

        return true;
    }
}
