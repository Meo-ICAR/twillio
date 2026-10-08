<?php

namespace Tests\Feature;

use Tests\TestCase;

class GrafoPageTest extends TestCase
{
    public function test_la_pagina_del_grafo_e_pubblica_e_sempre_allineata_alla_config(): void
    {
        $response = $this->get('/grafo-domande')->assertOk();

        $response->assertSee('Grafo delle domande del bot')
            ->assertSee('Richiedi Finanziamento')
            ->assertSee('Perfeziona Finanziamento')
            ->assertSee('<pre class="mermaid">', false);

        $first = array_key_first(config('finanziamento.flows.richiesta.nodes'));
        $response->assertSee($first.'[', false);
    }

    public function test_il_grafo_non_contiene_dati_personali_ne_di_sistema(): void
    {
        $html = $this->get('/grafo-domande')->getContent();

        foreach (['@example.com', 'password', 'token', 'APP_KEY'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $html);
        }
    }

    public function test_la_pagina_del_grafo_rimanda_alla_home(): void
    {
        $this->get('/grafo-domande')->assertSee('href="/"', false);
    }

    public function test_la_home_rimanda_al_grafo_delle_domande(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="/grafo-domande"', false)
            ->assertSee('domande');
    }
}
