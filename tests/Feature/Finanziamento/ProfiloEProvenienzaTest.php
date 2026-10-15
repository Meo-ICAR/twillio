<?php

namespace Tests\Feature\Finanziamento;

use App\Services\Checks\CheckContext;
use App\Services\Checks\RegistroImpreseCheck;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfiloEProvenienzaTest extends TestCase
{
    private const VAT = '09006331210';

    public function test_il_registro_imprese_ricava_ragione_sociale_e_sede(): void
    {
        config(['unico-core.openapi.token' => 'T', 'unico-core.openapi.company_url' => 'https://company.openapi.com']);
        Http::fake(['company.openapi.com/IT-advanced/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/openapi-it-advanced.json')), true))]);
        $ctx = new CheckContext([]);

        $this->assertTrue((new RegistroImpreseCheck)->passes(self::VAT, $ctx));

        $this->assertNotEmpty($ctx->derived()['ragione_sociale'] ?? null);
        $this->assertNotEmpty($ctx->derived()['residenza'] ?? null);
    }

    public function test_se_il_registro_non_risponde_si_prosegue_senza_dati_ricavati(): void
    {
        config(['unico-core.openapi.token' => 'T', 'unico-core.openapi.company_url' => 'https://company.openapi.com']);
        Http::fake(['company.openapi.com/*' => Http::response(['message' => 'non trovato'], 404)]);
        $ctx = new CheckContext([]);

        $this->assertTrue((new RegistroImpreseCheck)->passes(self::VAT, $ctx));
        $this->assertSame([], $ctx->derived());
    }

    public function test_senza_token_si_prosegue_senza_dati_ricavati(): void
    {
        config(['unico-core.openapi.token' => null]);
        $ctx = new CheckContext([]);

        $this->assertTrue((new RegistroImpreseCheck)->passes(self::VAT, $ctx));
        $this->assertSame([], $ctx->derived());
    }

    public function test_una_partita_iva_non_valida_e_rifiutata(): void
    {
        $this->assertFalse((new RegistroImpreseCheck)->passes('12345678901', new CheckContext([])));
    }
}
