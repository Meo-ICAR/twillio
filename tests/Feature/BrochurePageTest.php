<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrochurePageTest extends TestCase
{
    public function test_la_brochure_mette_in_evidenza_risparmio_controlli_ai_e_segnalatori(): void
    {
        $this->get('/brochure')->assertOk()
            ->assertSee('Dove si risparmia')
            ->assertSee('Controlli AI')
            ->assertSee('Segnalatori')
            ->assertSee('Stima il tuo risparmio')
            ->assertSee('unicoagent_banner.png');
    }

    public function test_dice_che_la_decisione_resta_alle_persone_e_non_promette_certificazioni(): void
    {
        $html = strtolower(preg_replace('#<(style|script)>.*?</\1>#s', '', $this->get('/brochure')->getContent()));

        $this->assertStringContainsString('la decisione resta alle persone', $html);
        foreach (['iso 27001', 'soc 2', 'certificat', 'garantit', '100%', 'http://'] as $claim) {
            $this->assertStringNotContainsString($claim, $html, $claim);
        }
    }

    public function test_il_calcolatore_non_ha_cifre_inventate_nel_testo_e_si_dichiara_stima(): void
    {
        $this->get('/brochure')->assertOk()->assertSee('Stima indicativa')->assertSee('ipotesi da sostituire', false);
    }

    public function test_collega_manuale_e_home(): void
    {
        $this->get('/brochure')->assertSee('href="/manuale"', false)->assertSee('href="/"', false)->assertSee('hassisto.com', false);
    }

    public function test_la_home_rimanda_alla_brochure(): void
    {
        $this->get('/')->assertOk()->assertSee('href="/brochure"', false)->assertSee('Scarica la brochure');
    }
}
