<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_la_home_spiega_cosa_fa_il_servizio(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('WhatsApp')
            ->assertSee('Richiedi Finanziamento')
            ->assertSee('Perfeziona Finanziamento')
            ->assertSee('informativa')
            ->assertSee('cifrat')
            ->assertSee('/admin', false);
    }

    public function test_la_home_non_promette_certificazioni_non_possedute(): void
    {
        $html = strtolower($this->get('/')->getContent());

        foreach (['iso 27001', 'certificat', '100% conforme', 'garantit'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }

    public function test_la_home_non_ha_dipendenze_esterne_da_costruire(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('@vite', $html);
        $this->assertStringNotContainsString('http://', $html);
    }
}
