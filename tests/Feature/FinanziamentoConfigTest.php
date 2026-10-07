<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanziamentoConfigTest extends TestCase
{
    public function test_il_menu_rispetta_i_limiti_di_whatsapp(): void
    {
        $options = config('finanziamento.menu.options');

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato'], array_keys($options));
        $this->assertSame('Richiedi Finanziamento', $options['menu_richiedi']);
        $this->assertSame('Perfeziona Finanziamento', $options['menu_perfeziona']);
        foreach ($options as $title) {
            $this->assertLessThanOrEqual(24, mb_strlen($title));
        }
    }

    public function test_gli_alberi_sono_coerenti(): void
    {
        foreach (config('finanziamento.flows') as $flow => $def) {
            $nodes = $def['nodes'];
            $this->assertArrayHasKey($def['start'], $nodes, "$flow: nodo start mancante");
            $this->assertArrayHasKey($def['restart'], $nodes, "$flow: nodo restart mancante");

            $targets = [];
            foreach ($nodes as $name => $node) {
                $this->assertContains($node['type'], ['choice', 'text', 'code', 'file', 'summary'], "$flow.$name tipo");
                $this->assertNotEmpty($node['prompt'] ?? null, "$flow.$name senza prompt");
                $this->assertLessThanOrEqual(900, mb_strlen($node['prompt']), "$flow.$name prompt troppo lungo");

                if (in_array($node['type'], ['choice', 'summary'], true)) {
                    $this->assertLessThanOrEqual(10, count($node['options']), "$flow.$name troppe opzioni");
                    foreach ($node['options'] as $id => $title) {
                        $this->assertLessThanOrEqual(24, mb_strlen($title), "$flow.$name.$id titolo troppo lungo");
                    }
                }
                if ($node['type'] === 'text') {
                    $this->assertNotEmpty($node['rules'] ?? null, "$flow.$name senza regole");
                }
                if ($node['type'] === 'file') {
                    $this->assertNotEmpty($node['kind'] ?? null, "$flow.$name senza kind");
                }
                if ($node['type'] !== 'summary') {
                    $this->assertArrayHasKey('next', $node, "$flow.$name senza next");
                    $targets[$name] = (array) $node['next'];
                }
            }

            $seen = [];
            $queue = [$def['start'], $def['restart']];
            while ($queue) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $this->assertArrayHasKey($name, $nodes, "$flow: il nodo '$name' non esiste");
                $seen[$name] = true;
                array_push($queue, ...array_values($targets[$name] ?? []));
            }
            $this->assertSame([], array_values(array_diff(array_keys($nodes), array_keys($seen))), "$flow: nodi irraggiungibili");
        }
    }

    public function test_la_fase_anonima_non_chiede_dati_identificativi(): void
    {
        $forbidden = ['nome', 'cognome', 'codice_fiscale', 'telefono', 'email', 'ragione_sociale', 'partita_iva', 'residenza'];

        $this->assertSame([], array_intersect($forbidden, array_keys(config('finanziamento.flows.richiesta.nodes'))));
        foreach (config('finanziamento.flows.richiesta.nodes') as $name => $node) {
            $this->assertNotContains($node['type'], ['text', 'file'], "richiesta.$name non deve essere testo libero o file");
        }
    }
}
