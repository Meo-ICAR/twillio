<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompliancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_ha_le_sezioni_di_trasparenza(): void
    {
        $this->get('/compliance')->assertOk()
            ->assertSee('Trasparenza e conformità')
            ->assertSee('Flusso dei dati')
            ->assertSee('Ruoli')
            ->assertSee('Protezione dei dati')
            ->assertSee('Intelligenza artificiale e decisioni')
            ->assertSee('Sub-responsabili')
            ->assertSee('Certificazioni')
            ->assertSee('Misure di sicurezza')
            ->assertSee('Settore del credito')
            ->assertSee('Documenti')
            ->assertSee('Stato della trasparenza');
    }

    public function test_i_ruoli_sono_quelli_dichiarati(): void
    {
        Company::create(['name' => 'Credito Facile Spa', 'email' => 'privacy@creditofacile.example']);

        $this->get('/compliance')->assertOk()
            ->assertSee('Credito Facile Spa')
            ->assertSee('Titolare del trattamento')
            ->assertSee('Hassisto Srl')
            ->assertSee('Responsabile del trattamento')
            ->assertSee('non ha accesso ai dati')
            ->assertSee('backup')
            ->assertSee('privacy@creditofacile.example');
    }

    public function test_senza_azienda_mostra_i_segnaposto(): void
    {
        $this->get('/compliance')->assertOk()->assertSee('da completare');
    }

    public function test_la_conservazione_segue_la_configurazione(): void
    {
        config(['privacy.retention_days' => 45]);

        $this->get('/compliance')->assertOk()->assertSee('45 giorni')->assertDontSee('30 giorni');
    }

    public function test_elenca_i_sub_responsabili_e_il_fornitore_ai_come_previsto(): void
    {
        $html = $this->get('/compliance')->assertOk()
            ->assertSee('WhatsApp Business Platform')
            ->assertSee('Meta Platforms')
            ->assertSee('Anthropic')
            ->assertSee('Previsto')
            ->assertSee('non sono ancora attive')
            ->assertSee('addestrare')
            ->getContent();

        $this->assertStringNotContainsString('OpenAI', $html);
    }

    public function test_i_sub_responsabili_si_configurano(): void
    {
        config(['privacy.subprocessors' => [
            ['name' => 'Fornitore X', 'role' => 'Estrazione documenti', 'location' => 'UE', 'status' => 'attivo'],
        ]]);

        $this->get('/compliance')->assertOk()->assertSee('Fornitore X')->assertSee('Attivo')->assertDontSee('Meta Platforms (WhatsApp');
    }

    public function test_il_servizio_non_decide_sul_credito_ma_lo_fa_un_istruttore_oam(): void
    {
        $this->get('/compliance')->assertOk()
            ->assertSee('Il servizio non valuta il merito creditizio')
            ->assertSee('istruttore')
            ->assertSee('agente abilitato OAM')
            ->assertSee('la decisione resta sempre di una persona')
            ->assertSee('Regolamento (UE) 2024/1689');
    }

    public function test_le_certificazioni_previste_non_sono_mai_presentate_come_ottenute(): void
    {
        $html = $this->get('/compliance')->assertOk()->assertSee('CSA STAR')->assertSee('Prevista')->getContent();

        $this->assertStringNotContainsString('Ottenuta', $html);
    }

    public function test_una_certificazione_ottenuta_si_mostra_come_tale(): void
    {
        config(['privacy.certifications' => [['name' => 'CSA STAR', 'status' => 'ottenuta']]]);

        $this->get('/compliance')->assertOk()->assertSee('CSA STAR')->assertSee('Ottenuta')->assertDontSee('Prevista');
    }

    public function test_dice_che_i_documenti_vanno_all_ai_solo_dopo_l_informativa_verificata(): void
    {
        $this->get('/compliance')->assertOk()
            ->assertSee('solo dopo che l\'informativa firmata è stata verificata', false)
            ->assertSee('non vengono inviati all\'intelligenza artificiale', false);
    }

    public function test_non_usa_formule_assolute(): void
    {
        // Solo il testo visibile: il CSS contiene legittimamente valori come width:100%.
        $html = strtolower(preg_replace('#<style>.*?</style>#s', '', $this->get('/compliance')->getContent()));

        foreach (['iso 27001', 'soc 2', '100%', 'garantit', 'http://', 'conforme al 100'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }

    public function test_rimanda_all_informativa_e_viceversa_e_dalla_home(): void
    {
        $this->get('/compliance')->assertSee('href="/privacy"', false)->assertSee('href="/"', false);
        $this->get('/privacy')->assertSee('href="/compliance"', false);
        $this->get('/')->assertSee('href="/compliance"', false);
    }

    public function test_e_stampabile(): void
    {
        $this->get('/compliance')->assertSee('@media print', false);
    }

    public function test_con_la_chiave_ai_la_pagina_dichiara_l_ai_attiva_e_il_fornitore_attivo(): void
    {
        config(['services.anthropic.key' => 'sk-test']);

        $this->get('/compliance')->assertOk()
            ->assertSee('Anthropic')
            ->assertSee('Attivo')
            ->assertDontSee('Previsto')
            ->assertDontSee('non sono ancora attive')
            ->assertSee('lettura automatica dei documenti')
            ->assertSee('tenuto per contratto');
    }
}
