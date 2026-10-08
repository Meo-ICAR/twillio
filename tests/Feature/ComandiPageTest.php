<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComandiPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_riassume_menu_e_comandi_e_rimanda_al_manuale(): void
    {
        $this->get('/comandi')->assertOk()
            ->assertSee('Richiedi Finanziamento')
            ->assertSee('indietro')
            ->assertSee('Torna alla domanda precedente')
            ->assertSee('href="/manuale#agente"', false);
    }
}
