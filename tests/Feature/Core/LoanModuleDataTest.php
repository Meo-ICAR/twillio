<?php

namespace Tests\Feature\Core;

use App\Models\LoanRequest;
use App\Services\Documents\LoanModuleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanModuleDataTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $personal, string $product = 'luce'): LoanRequest
    {
        return new LoanRequest(['code' => 'I1-0001', 'product' => $product, 'agent_wa_number' => '393927968199', 'answers' => ['consumo' => '3000'], 'personal' => $personal]);
    }

    private function privato(): array
    {
        return ['tipo_cliente' => 'privato', 'codice_fiscale' => 'RSSMRA80A01H501U', 'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'luogo_nascita' => 'Roma',
            'residenza' => 'Via Roma 1, 00100 Roma', 'telefono' => '3331234567', 'email' => 'mario@example.com', 'indirizzo_fornitura' => 'Via Verdi 2, 00100 Roma',
            'pod' => 'IT001E12345678', 'fornitore_attuale_nome' => 'ENEL ENERGIA S.P.A.', 'fornitore_attuale_piva' => '06655971007'];
    }

    public function test_un_privato_ha_le_chiavi_del_cliente_e_della_fornitura(): void
    {
        $d = (new LoanModuleData)->for($this->loan($this->privato()));

        $this->assertSame('Rossi', $d->get('client.name'));
        $this->assertSame('Rossi Mario', $d->get('client.nominativo'));
        $this->assertSame('RSSMRA80A01H501U', $d->get('client.tax_code'));
        $this->assertSame('Via Roma 1, 00100 Roma', $d->get('client.address.full'));
        $this->assertTrue($d->get('client.is_person'));
        $this->assertSame('IT001E12345678', $d->get('fornitura.pod'));
        $this->assertNull($d->get('fornitura.pdr'));
        $this->assertSame('06655971007', $d->get('fornitura.fornitore_attuale_piva'));
        $this->assertSame('I1-0001', $d->get('pratica.codice'));
        $this->assertSame(now()->format('d/m/Y'), $d->get('pratica.oggi'));
        $this->assertNull($d->get('client.vat_number'));
    }

    public function test_un_azienda_ha_ragione_sociale_e_partita_iva_e_firma_il_legale_rappresentante(): void
    {
        $loan = $this->loan(['tipo_cliente' => 'azienda', 'ragione_sociale' => 'ACME Srl', 'partita_iva' => 'IT12345678903'] + $this->privato());
        $m = new LoanModuleData;

        $d = $m->for($loan);
        $signer = $m->signer($loan);

        $this->assertFalse($d->get('client.is_person'));
        $this->assertSame('ACME Srl', $d->get('client.company_name'));
        $this->assertSame('12345678903', $d->get('client.vat_number'));
        $this->assertSame('Mario', $signer['first_name']);
        $this->assertSame('RSSMRA80A01H501U', $signer['tax_code']);
    }

    public function test_i_dati_mancanti_restano_assenti_non_stringhe_vuote(): void
    {
        $d = (new LoanModuleData)->for($this->loan(['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => '']));

        $this->assertNull($d->get('client.first_name'));
        $this->assertNull($d->get('client.email'));
        $this->assertFalse($d->has('client.tax_code'));
    }

    public function test_per_il_gas_c_e_il_pdr_e_non_il_pod(): void
    {
        $d = (new LoanModuleData)->for($this->loan(['tipo_cliente' => 'privato', 'pdr' => '12345678901234'], 'gas'));

        $this->assertSame('12345678901234', $d->get('fornitura.pdr'));
        $this->assertNull($d->get('fornitura.pod'));
        $this->assertSame('gas', $d->get('fornitura.tipo'));
    }
}
