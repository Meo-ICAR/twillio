<?php

namespace Tests\Feature\Finanziamento;

use App\Jobs\FetchCadastreData;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Unico\Core\Clients\OpenApiCadastreService;

class CatastoInBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $personal = ['codice_fiscale' => 'RSSMRA80A01H501U', 'residenza' => 'Via Roma 1, 00100 Roma']): LoanRequest
    {
        return LoanRequest::create(['code' => 'I1-0001', 'agent_wa_number' => '393927968199', 'product' => 'luce', 'status' => 'perfezionata', 'answers' => ['profilo' => 'privato'], 'personal' => $personal]);
    }

    public function test_il_job_salva_i_dati_catastali_simulati(): void
    {
        config(['unico-core.openapi.catasto_live' => false]);
        $loan = $this->loan();

        (new FetchCadastreData($loan->id))->handle(app(OpenApiCadastreService::class));

        $this->assertFalse($loan->fresh()->cadastre['live']);
        $this->assertNotEmpty($loan->fresh()->cadastre['parcels']);
    }

    public function test_se_il_servizio_non_risponde_non_propaga_e_non_salva(): void
    {
        config(['unico-core.openapi.catasto_live' => true]);
        $loan = $this->loan();

        (new FetchCadastreData($loan->id))->handle(app(OpenApiCadastreService::class));

        $this->assertNull($loan->fresh()->cadastre);
    }

    public function test_senza_codice_fiscale_o_indirizzo_non_fa_nulla(): void
    {
        $loan = $this->loan(['codice_fiscale' => 'RSSMRA80A01H501U']);

        (new FetchCadastreData($loan->id))->handle(app(OpenApiCadastreService::class));

        $this->assertNull($loan->fresh()->cadastre);
    }
}
