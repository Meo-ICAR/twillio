<?php

namespace Tests\Unit\Checks;

use App\Services\Checks\CheckContext;
use App\Services\Checks\CodiceFiscaleCheck;
use App\Services\Checks\IbanCheck;
use App\Services\Checks\MaggiorenneCheck;
use PHPUnit\Framework\TestCase;

class BuiltInChecksTest extends TestCase
{
    public function test_il_contesto_raccoglie_dati_ricavati_ed_errore(): void
    {
        $ctx = new CheckContext(['cognome' => 'Rossi'], ['anni' => 21]);

        $ctx->set('sesso', 'M');
        $this->assertSame(['sesso' => 'M'], $ctx->derived());
        $this->assertSame('Rossi', $ctx->get('cognome'));
        $this->assertSame('M', $ctx->get('sesso'), 'vede anche quanto ricavato nello stesso passaggio');
        $this->assertSame(21, $ctx->param('anni', 18));
        $this->assertSame(18, $ctx->param('altro', 18));
        $this->assertNull($ctx->error());

        $this->assertFalse($ctx->fail('Non va'));
        $this->assertSame('Non va', $ctx->error());
    }

    public function test_codice_fiscale_ricava_data_sesso_e_luogo(): void
    {
        $ctx = new CheckContext([]);

        $this->assertTrue((new CodiceFiscaleCheck)->passes('RSSMRA80A01H501U', $ctx));
        $this->assertSame(['data_nascita' => '01/01/1980', 'sesso' => 'M', 'luogo_nascita' => 'Roma (RM)'], $ctx->derived());
        $this->assertSame(['data_nascita', 'sesso', 'luogo_nascita'], (new CodiceFiscaleCheck)->derives());
    }

    public function test_codice_fiscale_senza_luogo_noto_non_inventa_il_luogo(): void
    {
        $ctx = new CheckContext([]);

        $this->assertTrue((new CodiceFiscaleCheck)->passes('RSSMRA80A01Z404U', $ctx));
        $this->assertArrayNotHasKey('luogo_nascita', $ctx->derived());
    }

    public function test_codice_fiscale_non_interpretabile_non_passa_e_non_ricava_nulla(): void
    {
        $ctx = new CheckContext([]);

        $this->assertFalse((new CodiceFiscaleCheck)->passes('RSSMRA80Z01H501U', $ctx));
        $this->assertSame([], $ctx->derived());
        $this->assertNull($ctx->error(), 'il messaggio è quello della domanda');
    }

    public function test_iban_controlla_il_checksum(): void
    {
        $check = new IbanCheck;

        $this->assertTrue($check->passes('IT60X0542811101000000123456', new CheckContext([])));
        $this->assertTrue($check->passes('it60 x054 2811 1010 0000 0123 456', new CheckContext([])));
        $this->assertFalse($check->passes('IT61X0542811101000000123456', new CheckContext([])));
        $this->assertSame([], $check->derives());
    }

    public function test_maggiorenne_usa_un_campo_ricavato_o_il_valore_stesso(): void
    {
        $check = new MaggiorenneCheck;
        $today = date('d/m/Y', strtotime('-18 years'));
        $tomorrow = date('d/m/Y', strtotime('-18 years +1 day'));

        // dal campo ricavato da un controllo precedente
        $this->assertTrue($check->passes('x', new CheckContext(['data_nascita' => $today], ['campo' => 'data_nascita'])));
        $ctx = new CheckContext(['data_nascita' => $tomorrow], ['campo' => 'data_nascita', 'messaggio' => 'Il cliente è minorenne.']);
        $this->assertFalse($check->passes('x', $ctx));
        $this->assertSame('Il cliente è minorenne.', $ctx->error());

        // dal valore della risposta
        $this->assertTrue($check->passes($today, new CheckContext([])));
        $this->assertFalse($check->passes($tomorrow, new CheckContext([])));

        // soglia configurabile
        $this->assertFalse($check->passes($today, new CheckContext([], ['anni' => 21])));
    }

    public function test_maggiorenne_non_blocca_se_non_trova_una_data(): void
    {
        $this->assertTrue((new MaggiorenneCheck)->passes('boh', new CheckContext([])));
        $this->assertTrue((new MaggiorenneCheck)->passes('x', new CheckContext([], ['campo' => 'data_nascita'])));
    }
}
