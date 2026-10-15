<?php

namespace Tests\Unit;

use App\Services\Checks\CheckContext;
use App\Services\Checks\EmailCheck;
use App\Services\Checks\MobileCheck;
use PHPUnit\Framework\TestCase;

/** I controlli sulle risposte delegano alle routine di unico-core e dicono all'agente cosa correggere. */
class ContactChecksTest extends TestCase
{
    public function test_email_valida_o_con_spazi_e_maiuscole_passa(): void
    {
        foreach (['mario@example.com', '  Mario.Rossi@Example.IT '] as $email) {
            $this->assertTrue((new EmailCheck)->passes($email, new CheckContext([])), $email);
        }
    }

    public function test_email_non_valida_si_rifiuta_con_il_motivo(): void
    {
        $ctx = new CheckContext([]);

        $this->assertFalse((new EmailCheck)->passes('mario@example', $ctx));
        $this->assertStringContainsString('suffisso', (string) $ctx->error());
    }

    public function test_un_refuso_di_dominio_propone_la_correzione(): void
    {
        $ctx = new CheckContext([]);

        $this->assertFalse((new EmailCheck)->passes('mario@gmial.com', $ctx));
        $this->assertStringContainsString('mario@gmail.com', (string) $ctx->error());
    }

    public function test_cellulare_italiano_o_estero_passa_il_fisso_no(): void
    {
        $check = new MobileCheck;

        $this->assertTrue($check->passes('333 123 4567', new CheckContext([])));
        $this->assertTrue($check->passes('+39 333 1234567', new CheckContext([])));
        $this->assertTrue($check->passes('+44 7911 123456', new CheckContext([])));

        $ctx = new CheckContext([]);
        $this->assertFalse($check->passes('06 1234567', $ctx));
        $this->assertStringContainsString('cellulare', (string) $ctx->error());
    }

    public function test_numero_con_lettere_si_rifiuta(): void
    {
        $ctx = new CheckContext([]);

        $this->assertFalse((new MobileCheck)->passes('333abc4567', $ctx));
        $this->assertStringContainsString('cifre', (string) $ctx->error());
    }
}
