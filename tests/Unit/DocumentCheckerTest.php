<?php

namespace Tests\Unit;

use App\Services\Documents\DocumentChecker;
use PHPUnit\Framework\TestCase;

class DocumentCheckerTest extends TestCase
{
    private function personal(): array
    {
        return [
            'cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U', 'data_nascita' => '01/01/1980',
            'documento_numero' => 'AB123456', 'documento_scadenza' => '01/01/2035',
        ];
    }

    private function identity(array $override = []): array
    {
        return $override + [
            'kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U',
            'birth_date' => '01/01/1980', 'birth_place' => 'ROMA', 'document_number' => 'AB 123456', 'expiry_date' => '01/01/2035',
        ];
    }

    private function check(string $kind, array $extracted, ?array $personal = null): array
    {
        return (new DocumentChecker)->check($kind, $extracted, $personal ?? $this->personal(), '2026-10-07');
    }

    public function test_documento_coerente_non_ha_difformita(): void
    {
        $this->assertSame([], $this->check('documento_identita', $this->identity()));
    }

    public function test_confronta_ignorando_maiuscole_accenti_e_spazi(): void
    {
        $this->assertSame([], $this->check('documento_identita', $this->identity(['surname' => 'rossì', 'name' => ' Mario '])));
    }

    public function test_nomi_composti_sul_documento_sono_accettati(): void
    {
        $this->assertSame([], $this->check('documento_identita', $this->identity(['name' => 'MARIO GIUSEPPE'])));
        $this->assertSame([], $this->check('documento_identita', $this->identity(['surname' => 'DE ROSSI']), array_merge($this->personal(), ['cognome' => 'Rossi'])));
    }

    public function test_segnala_cognome_e_nome_diversi(): void
    {
        $out = $this->check('documento_identita', $this->identity(['surname' => 'BIANCHI', 'name' => 'LUCA']));

        $this->assertCount(2, $out);
        $this->assertStringContainsString('Cognome', $out[0]);
        $this->assertStringContainsString('BIANCHI', $out[0]);
        $this->assertStringContainsString('Rossi', $out[0]);
        $this->assertStringContainsString('Nome', $out[1]);
    }

    public function test_segnala_codice_fiscale_data_di_nascita_numero_e_scadenza_diversi(): void
    {
        $out = $this->check('documento_identita', $this->identity([
            'fiscal_code' => 'RSSMRA80A01H501X', 'birth_date' => '02/01/1980', 'document_number' => 'ZZ999999', 'expiry_date' => '01/01/2036',
        ]));

        $this->assertCount(4, $out);
        $this->assertStringContainsString('Codice fiscale', $out[0]);
        $this->assertStringContainsString('Data di nascita', $out[1]);
        $this->assertStringContainsString('Numero documento', $out[2]);
        $this->assertStringContainsString('Scadenza', $out[3]);
    }

    public function test_segnala_il_documento_scaduto(): void
    {
        $out = $this->check('documento_identita', $this->identity(['expiry_date' => '01/01/2020']), array_merge($this->personal(), ['documento_scadenza' => '01/01/2020']));

        $this->assertCount(1, $out);
        $this->assertStringContainsString('scaduto', $out[0]);
    }

    public function test_campi_non_leggibili_sul_documento_o_non_dichiarati_non_generano_difformita(): void
    {
        $this->assertSame([], $this->check('documento_identita', $this->identity(['fiscal_code' => null, 'birth_place' => null])));
        $this->assertSame([], $this->check('documento_identita', $this->identity(), ['cognome' => 'Rossi']));
    }

    public function test_documento_illeggibile(): void
    {
        $out = $this->check('documento_identita', ['legible' => false, 'kind_detected' => 'identita']);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('non è leggibile', $out[0]);
    }

    public function test_tipo_di_documento_sbagliato(): void
    {
        $out = $this->check('documento_identita', $this->identity(['kind_detected' => 'reddito']));

        $this->assertCount(1, $out);
        $this->assertStringContainsString('documento di reddito', $out[0]);
        $this->assertStringContainsString('documento d\'identità', $out[0]);
    }

    public function test_documento_non_riconosciuto(): void
    {
        $out = $this->check('reddito', ['legible' => true, 'kind_detected' => 'altro']);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('non riconosco', strtolower($out[0]));
    }

    public function test_tessera_codice_fiscale_e_documento_di_reddito_confrontano_intestatario(): void
    {
        $this->assertSame([], $this->check('codice_fiscale', ['kind_detected' => 'codice_fiscale', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U']));

        $out = $this->check('reddito', ['kind_detected' => 'reddito', 'legible' => true, 'surname' => 'VERDI', 'name' => 'MARIO', 'fiscal_code' => null]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('Cognome', $out[0]);
    }
}
