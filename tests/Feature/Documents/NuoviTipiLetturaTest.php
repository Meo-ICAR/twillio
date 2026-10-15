<?php

namespace Tests\Feature\Documents;

use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Services\Checks\DocumentContext;
use App\Services\Checks\EstraiDatiCheck;
use App\Services\Documents\DocumentChecker;
use Tests\TestCase;

class NuoviTipiLetturaTest extends TestCase
{
    private function proposals(string $kind, array $fields): array
    {
        $ctx = new DocumentContext(new Attachment, new LoanRequest, $kind, fn () => $fields);

        return (new EstraiDatiCheck)->inspect($ctx)->proposals;
    }

    public function test_estrai_dati_propone_le_chiavi_della_bolletta(): void
    {
        $proposals = $this->proposals('bolletta', [
            'surname' => 'Rossi', 'pod' => 'it001e12345678', 'pdr' => null,
            'supply_address' => 'Via Roma 1, 00100 Roma', 'committed_power' => '3', 'previous_supplier' => 'Enel Energia',
        ]);

        $this->assertSame(['pod' => 'IT001E12345678', 'indirizzo_fornitura' => 'Via Roma 1, 00100 Roma', 'potenza' => '3', 'fornitore_attuale' => 'Enel Energia'], $proposals);
    }

    public function test_estrai_dati_propone_visura_ed_estratto_conto(): void
    {
        $this->assertSame(
            ['ragione_sociale' => 'ACME Srl', 'partita_iva' => '12345678903', 'residenza' => 'Via Po 2, 10100 Torino'],
            $this->proposals('visura', ['company_name' => 'ACME Srl', 'vat_number' => '12345678903', 'registered_office' => 'Via Po 2, 10100 Torino']),
        );
        $this->assertSame(
            ['iban' => 'IT60X0542811101000000123456', 'intestatario_iban' => 'Mario Rossi'],
            $this->proposals('estratto_conto', ['iban' => 'it60 x054 2811 1010 0000 0123 456', 'account_holder' => 'Mario Rossi']),
        );
    }

    public function test_il_checker_riconosce_i_nuovi_tipi(): void
    {
        $checker = new DocumentChecker;

        $this->assertSame([], $checker->typeIssues('bolletta', ['kind_detected' => 'bolletta']));
        $this->assertNotSame([], $checker->typeIssues('bolletta', ['kind_detected' => 'identita']));
        $this->assertSame([], $checker->typeIssues('visura', ['kind_detected' => 'visura']));
        $this->assertSame([], $checker->typeIssues('estratto_conto', ['kind_detected' => 'estratto_conto']));
    }
}
