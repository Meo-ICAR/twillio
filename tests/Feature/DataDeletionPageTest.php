<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataDeletionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_spiega_come_chiedere_la_cancellazione(): void
    {
        $this->get('/cancellazione-dati')->assertOk()
            ->assertSee('Come chiedere la cancellazione dei tuoi dati')
            ->assertSee('codice della pratica')
            ->assertSee('FIN-')
            ->assertSee('un mese')
            ->assertSee('Garante')
            ->assertSee('chat di WhatsApp')
            ->assertSee('Hassisto Srl')
            ->assertSee('backup');
    }

    public function test_indica_il_contatto_del_titolare_oppure_il_segnaposto(): void
    {
        $this->get('/cancellazione-dati')->assertOk()->assertSee('da completare');

        Company::create(['name' => 'Credito Facile Spa', 'email' => 'privacy@creditofacile.example', 'retention_perfected' => 'Dieci anni dalla chiusura.']);

        $this->get('/cancellazione-dati')->assertOk()
            ->assertSee('Credito Facile Spa')
            ->assertSee('privacy@creditofacile.example')
            ->assertSee('mailto:privacy@creditofacile.example', false)
            ->assertSee('Dieci anni dalla chiusura.')
            ->assertDontSee('da completare');
    }

    public function test_dice_cosa_viene_cancellato_e_cosa_puo_restare(): void
    {
        config(['privacy.retention_days' => 45]);

        $this->get('/cancellazione-dati')->assertOk()
            ->assertSee('documenti')
            ->assertSee('annotazioni')
            ->assertSee('obblighi di legge')
            ->assertSee('45 giorni');
    }

    public function test_non_usa_formule_assolute(): void
    {
        $html = strtolower(preg_replace('#<style>.*?</style>#s', '', $this->get('/cancellazione-dati')->getContent()));

        foreach (['garantit', '100%', 'http://', 'immediat'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }

    public function test_e_collegata_da_informativa_trasparenza_e_home(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('href="/cancellazione-dati"', false);
        $this->get('/compliance')->assertOk()->assertSee('href="/cancellazione-dati"', false);
        $this->get('/')->assertOk()->assertSee('href="/cancellazione-dati"', false);
        $this->get('/cancellazione-dati')->assertSee('href="/privacy"', false)->assertSee('href="/"', false);
    }
}
