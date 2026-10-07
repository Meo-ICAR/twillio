<?php

namespace Tests\Unit\Checks;

use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Services\Checks\DatiCoerentiCheck;
use App\Services\Checks\DocumentCheckResult;
use App\Services\Checks\DocumentContext;
use App\Services\Checks\EstraiDatiCheck;
use App\Services\Checks\InformativaFirmataCheck;
use App\Services\Checks\TipoDocumentoCheck;
use PHPUnit\Framework\TestCase;

class DocumentChecksTest extends TestCase
{
    private function context(array $fields, string $kind = 'documento_identita', array $declared = [], array $params = []): DocumentContext
    {
        return new DocumentContext(new Attachment, new LoanRequest, $kind, fn () => $fields, $declared, $params);
    }

    private function identity(array $override = []): array
    {
        return $override + [
            'kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => 'RSSMRA80A01H501U',
            'birth_date' => '01/01/1980', 'document_number' => 'AB123456', 'expiry_date' => '01/01/2035',
        ];
    }

    public function test_il_risultato_porta_esito_problemi_e_dati_proposti(): void
    {
        $ok = DocumentCheckResult::ok(['cognome' => 'ROSSI']);
        $ko = DocumentCheckResult::fail(['Non va']);

        $this->assertTrue($ok->passed());
        $this->assertSame(['cognome' => 'ROSSI'], $ok->proposals);
        $this->assertFalse($ko->passed());
        $this->assertSame(['Non va'], $ko->issues);
        $this->assertSame([], $ko->proposals);
    }

    public function test_il_contesto_legge_i_campi_una_sola_volta(): void
    {
        $calls = 0;
        $ctx = new DocumentContext(new Attachment, new LoanRequest, 'reddito', function () use (&$calls) {
            $calls++;

            return ['legible' => true];
        });

        $ctx->fields();
        $ctx->fields();

        $this->assertSame(1, $calls);
        $this->assertSame('reddito', $ctx->readerKind);
    }

    public function test_tipo_documento_controlla_leggibilita_e_tipo(): void
    {
        $check = new TipoDocumentoCheck;

        $this->assertTrue($check->inspect($this->context($this->identity()))->passed());

        $illegible = $check->inspect($this->context(['legible' => false, 'kind_detected' => 'identita']));
        $this->assertFalse($illegible->passed());
        $this->assertStringContainsString('non è leggibile', $illegible->issues[0]);

        $wrong = $check->inspect($this->context($this->identity(['kind_detected' => 'reddito'])));
        $this->assertStringContainsString('documento di reddito', $wrong->issues[0]);

        $other = $check->inspect($this->context(['legible' => true, 'kind_detected' => 'altro'], 'reddito'));
        $this->assertStringContainsString('Non riconosco', $other->issues[0]);
    }

    public function test_dati_coerenti_confronta_con_quelli_dichiarati(): void
    {
        $check = new DatiCoerentiCheck;
        $declared = ['cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U'];

        $this->assertTrue($check->inspect($this->context($this->identity(), 'documento_identita', $declared))->passed());

        $bad = $check->inspect($this->context($this->identity(['surname' => 'BIANCHI']), 'documento_identita', $declared));
        $this->assertFalse($bad->passed());
        $this->assertStringContainsString('BIANCHI', $bad->issues[0]);
    }

    public function test_dati_coerenti_non_blocca_se_non_c_e_nulla_da_confrontare(): void
    {
        $this->assertTrue((new DatiCoerentiCheck)->inspect($this->context($this->identity()))->passed());
    }

    public function test_dati_coerenti_segnala_il_documento_scaduto(): void
    {
        $result = (new DatiCoerentiCheck)->inspect($this->context($this->identity(['expiry_date' => '01/01/2020'])));

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('scaduto', $result->issues[0]);
    }

    public function test_informativa_conforme_e_firmata_passa(): void
    {
        $result = (new InformativaFirmataCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'informativa', 'matches_template' => true, 'signed' => true], 'informativa'
        ));

        $this->assertTrue($result->passed());
    }

    public function test_informativa_non_firmata_non_passa(): void
    {
        $result = (new InformativaFirmataCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'informativa', 'matches_template' => true, 'signed' => false], 'informativa'
        ));

        $this->assertFalse($result->passed());
        $this->assertStringContainsString('non risulta firmata', $result->issues[0]);
    }

    public function test_informativa_diversa_dal_nostro_modulo_non_passa(): void
    {
        $other = (new InformativaFirmataCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'informativa', 'matches_template' => false, 'signed' => true], 'informativa'
        ));
        $this->assertStringContainsString('nostro modulo', $other->issues[0]);

        $notNotice = (new InformativaFirmataCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'reddito', 'matches_template' => null, 'signed' => null], 'informativa'
        ));
        $this->assertStringContainsString('nostro modulo', $notNotice->issues[0]);
    }

    public function test_informativa_illeggibile_o_con_firma_non_determinabile_non_passa(): void
    {
        $illegible = (new InformativaFirmataCheck)->inspect($this->context(['legible' => false, 'kind_detected' => 'informativa'], 'informativa'));
        $this->assertStringContainsString('non è leggibile', $illegible->issues[0]);

        $unknown = (new InformativaFirmataCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'informativa', 'matches_template' => true, 'signed' => null], 'informativa'
        ));
        $this->assertFalse($unknown->passed(), 'se non si vede la firma non si dà per firmata');
    }

    public function test_estrai_dati_propone_i_campi_con_i_nomi_del_dialogo(): void
    {
        $result = (new EstraiDatiCheck)->inspect($this->context($this->identity(['fiscal_code' => 'rssmra80a01h501u'])));

        $this->assertTrue($result->passed());
        $this->assertSame([
            'cognome' => 'ROSSI', 'nome' => 'MARIO', 'codice_fiscale' => 'RSSMRA80A01H501U',
            'documento_numero' => 'AB123456', 'documento_scadenza' => '01/01/2035',
        ], $result->proposals);
    }

    public function test_estrai_dati_salta_i_campi_non_visibili_e_normalizza_il_codice_fiscale(): void
    {
        $result = (new EstraiDatiCheck)->inspect($this->context(
            ['legible' => true, 'kind_detected' => 'codice_fiscale', 'surname' => 'Rossi', 'name' => null, 'fiscal_code' => 'rssmra 80a01 h501u'], 'codice_fiscale'
        ));

        $this->assertSame(['cognome' => 'Rossi', 'codice_fiscale' => 'RSSMRA80A01H501U'], $result->proposals);
    }

    public function test_i_nomi_e_le_descrizioni_servono_al_pannello(): void
    {
        foreach ([new TipoDocumentoCheck, new DatiCoerentiCheck, new InformativaFirmataCheck, new EstraiDatiCheck] as $check) {
            $this->assertNotSame('', $check->label());
            $this->assertNotSame('', $check->description());
        }
    }
}
