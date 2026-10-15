<?php

namespace App\Services\Documents;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use Unico\Core\Pdf\ResolvedModuleData;

/** Traduce i dati raccolti dal bot nelle chiavi con cui i moduli PDF del core si compilano. */
class LoanModuleData
{
    public function for(LoanRequest $loan): ResolvedModuleData
    {
        $p = $loan->personal ?? [];
        $isPerson = ($p['tipo_cliente'] ?? 'privato') !== 'azienda';
        $agent = $loan->agent_wa_number ? Fornitore::findByWhatsApp($loan->agent_wa_number) : null;

        $values = [
            'client.name' => $p['cognome'] ?? null,
            'client.first_name' => $p['nome'] ?? null,
            'client.nominativo' => trim(($p['cognome'] ?? '').' '.($p['nome'] ?? '')),
            'client.tax_code' => $p['codice_fiscale'] ?? null,
            'client.birth_date' => $p['data_nascita'] ?? null,
            'client.birth_place' => $p['luogo_nascita'] ?? null,
            'client.address.full' => $p['residenza'] ?? null,
            'client.email' => $p['email'] ?? null,
            'client.phone' => $p['telefono'] ?? null,
            'client.is_person' => $isPerson,
            'client.company_name' => $isPerson ? null : ($p['ragione_sociale'] ?? null),
            'client.vat_number' => $isPerson ? null : preg_replace('/^IT/i', '', (string) ($p['partita_iva'] ?? '')),
            'pratica.oggi' => now()->format('d/m/Y'),
            'pratica.codice' => $loan->code,
            'pratica.prodotto' => $loan->product,
            'fornitura.tipo' => $loan->product,
            'fornitura.pod' => $p['pod'] ?? null,
            'fornitura.pdr' => $p['pdr'] ?? null,
            'fornitura.indirizzo' => ($p['indirizzo_fornitura'] ?? null) ?: ($p['residenza'] ?? null),
            'fornitura.fornitore_attuale' => $p['fornitore_attuale_nome'] ?? ($p['fornitore_attuale'] ?? null),
            'fornitura.fornitore_attuale_piva' => $p['fornitore_attuale_piva'] ?? null,
            'fornitura.potenza' => $p['potenza'] ?? null,
            'fornitura.titolo_possesso' => $p['titolo_possesso'] ?? null,
            'pagamento.iban' => $p['iban'] ?? null,
            'pagamento.intestatario' => ($p['intestatario_iban'] ?? null) ?: trim(($p['cognome'] ?? '').' '.($p['nome'] ?? '')),
            'client.destinazione_uso' => $p['destinazione_uso'] ?? null,
            'agent.name' => $agent?->display_name,
        ];

        // Un valore assente è null: mai una stringa vuota (false resta un valore valido).
        return new ResolvedModuleData(array_map(fn ($v) => $v === '' ? null : $v, $values));
    }

    /** Chi firma: il cliente, o il legale rappresentante per un'azienda (i dati personali raccolti sono i suoi). */
    public function signer(LoanRequest $loan): array
    {
        $p = $loan->personal ?? [];

        return [
            'first_name' => (string) ($p['nome'] ?? ''),
            'last_name' => (string) ($p['cognome'] ?? ''),
            'email' => ($p['email'] ?? '') ?: null,
            'phone' => ($p['telefono'] ?? '') ?: null,
            'tax_code' => ($p['codice_fiscale'] ?? '') ?: null,
        ];
    }
}
