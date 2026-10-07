<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_esiste_e_nomina_i_ruoli(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('Informativa sul trattamento dei dati personali')
            ->assertSee('Titolare del trattamento')
            ->assertSee('Responsabile del trattamento')
            ->assertSee('Hassisto Srl')
            ->assertSee('backup')
            ->assertSee('non ha accesso ai dati')
            ->assertSee('30 giorni');
    }

    public function test_i_dati_del_titolare_vengono_dalla_tabella_companies(): void
    {
        Company::create([
            'name' => 'Credito Facile Spa',
            'address' => 'Via Roma 1, 20100 Milano',
            'email' => 'privacy@creditofacile.example',
            'dpo_email' => 'dpo@creditofacile.example',
            'retention_perfected' => 'Per 10 anni dalla chiusura, come previsto dalla legge.',
        ]);

        $this->get('/privacy')->assertOk()
            ->assertSee('Credito Facile Spa')
            ->assertSee('Via Roma 1, 20100 Milano')
            ->assertSee('privacy@creditofacile.example')
            ->assertSee('dpo@creditofacile.example')
            ->assertSee('Per 10 anni dalla chiusura')
            ->assertDontSee('da completare');
    }

    public function test_senza_azienda_i_segnaposto_sono_visibili(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('da completare');
    }

    public function test_i_campi_vuoti_dell_azienda_restano_segnaposto(): void
    {
        Company::create(['name' => 'Credito Facile Spa']);

        $html = $this->get('/privacy')->assertOk()->assertSee('Credito Facile Spa')->getContent();

        $this->assertStringContainsString('da completare', $html);
    }

    public function test_i_giorni_di_conservazione_seguono_la_configurazione(): void
    {
        config(['privacy.retention_days' => 45]);

        $this->get('/privacy')->assertOk()->assertSee('45 giorni')->assertDontSee('30 giorni');
    }

    public function test_la_home_rimanda_all_informativa(): void
    {
        $this->get('/')->assertOk()->assertSee('href="/privacy"', false);
    }

    public function test_la_pagina_e_stampabile_con_riquadro_di_presa_visione(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('@media print', false)
            ->assertSee('Per presa visione');
    }

    public function test_la_pagina_non_promette_certificazioni_o_garanzie_assolute(): void
    {
        $html = strtolower($this->get('/privacy')->getContent());

        foreach (['iso 27001', 'certificat', '100%', 'garantit', 'http://'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }

    public function test_i_destinatari_citano_il_fornitore_ai_solo_quando_e_attivo(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('Anthropic');

        config(['services.anthropic.key' => 'sk-test']);

        $this->get('/privacy')->assertOk()->assertSee('Anthropic')->assertSee('lettura automatica dei documenti');
    }
}
