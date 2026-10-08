<?php

namespace Tests\Feature;

use App\Services\Conversation\FlowGraph;
use Tests\TestCase;

class FlowGraphTest extends TestCase
{
    /** @return array<string,string[]> nodo => destinazioni uniche */
    private function edges(string $flow): array
    {
        $edges = [];
        foreach (config("finanziamento.flows.{$flow}.nodes") as $name => $node) {
            if ($node['type'] !== 'summary') {
                $edges[$name] = array_values(array_unique((array) $node['next']));
            }
        }

        return $edges;
    }

    public function test_il_grafo_contiene_ogni_domanda_e_ogni_salto(): void
    {
        foreach (array_keys(config('finanziamento.flows')) as $flow) {
            $mermaid = (new FlowGraph)->mermaid($flow);

            $this->assertStringStartsWith('flowchart TD', $mermaid);
            foreach (array_keys(config("finanziamento.flows.{$flow}.nodes")) as $name) {
                $this->assertMatchesRegularExpression('/^\s+'.preg_quote($name, '/').'[\[\(\{]/m', $mermaid, "$flow: nodo $name mancante");
            }
            foreach ($this->edges($flow) as $from => $targets) {
                foreach ($targets as $to) {
                    $this->assertMatchesRegularExpression('/^\s+'.preg_quote($from, '/').' -->(\|"[^"]*"\|)? '.preg_quote($to, '/').'$/m', $mermaid, "$flow: salto $from → $to mancante");
                }
            }
        }
    }

    public function test_attesa_e_conferma_dei_dati_letti_hanno_una_forma_e_un_colore_propri(): void
    {
        $mermaid = (new FlowGraph)->mermaid('perfezionamento');

        $this->assertMatchesRegularExpression('/^\s+attesa_documenti\{\{"/m', $mermaid);
        $this->assertMatchesRegularExpression('/^\s+rivedi_dati\(\["/m', $mermaid);
        $this->assertStringContainsString('classDef wait', $mermaid);
        $this->assertStringContainsString('class attesa_documenti wait', $mermaid);
        $this->assertStringContainsString('class rivedi_dati review', $mermaid);
        $this->assertStringContainsString('attesa_documenti --> rivedi_dati', $mermaid);
        $this->assertStringContainsString('doc_reddito --> attesa_documenti', $mermaid);
    }

    public function test_la_legenda_spiega_le_forme_nuove(): void
    {
        $html = (new FlowGraph)->html();

        $this->assertStringContainsString('esagono = attesa dei controlli', $html);
        $this->assertStringContainsString('conferma dei dati letti', $html);
    }

    public function test_le_etichette_non_rompono_la_sintassi(): void
    {
        $mermaid = (new FlowGraph)->mermaid('richiesta');

        foreach (explode("\n", $mermaid) as $line) {
            $this->assertSame(0, substr_count($line, '"') % 2, "virgolette sbilanciate: $line");
            $this->assertStringNotContainsString("\n", $line);
        }
        $this->assertStringContainsString('presso l#39;attuale datore', $mermaid);
    }

    public function test_le_etichette_dei_salti_usano_i_titoli_delle_opzioni(): void
    {
        $mermaid = (new FlowGraph)->mermaid('richiesta');

        $this->assertStringContainsString('lavoro -->|"Dipendente privato / Dipendente pubblico"| contratto', $mermaid);
        $this->assertStringContainsString('impegni -->|"Sì"| rata', $mermaid);
    }

    public function test_la_pagina_html_e_autonoma_e_include_i_due_percorsi(): void
    {
        $html = (new FlowGraph)->html();

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertSame(3, substr_count($html, '<pre class="mermaid">'));
        $this->assertStringContainsString('Richiedi Finanziamento', $html);
        $this->assertStringContainsString('Perfeziona Finanziamento', $html);
        $this->assertStringContainsString('Carica documenti', $html);
        $this->assertStringContainsString('domande', $html);
    }

    public function test_il_comando_scrive_i_file(): void
    {
        $dir = sys_get_temp_dir().'/grafo-'.uniqid();

        $this->artisan('finanziamento:graph', ['--path' => $dir])->assertSuccessful();

        $this->assertFileExists("$dir/finanziamento-grafo.html");
        $this->assertFileExists("$dir/finanziamento-richiesta.mmd");
        $this->assertFileExists("$dir/finanziamento-perfezionamento.mmd");
        $this->assertStringStartsWith('flowchart TD', file_get_contents("$dir/finanziamento-richiesta.mmd"));
    }

    public function test_il_primo_riquadro_parte_al_30_per_cento_e_ha_i_comandi_di_zoom(): void
    {
        $html = (new FlowGraph)->html();

        $this->assertSame(1, substr_count($html, 'data-zoom="0.3"'));
        $this->assertSame(2, substr_count($html, 'data-zoom="1"'));
        $this->assertLessThan(strpos($html, 'data-zoom="1"'), strpos($html, 'data-zoom="0.3"'));
        foreach (['data-zoom-in', 'data-zoom-out', 'data-zoom-reset'] as $control) {
            $this->assertSame(3, substr_count($html, '<button type="button" '.$control.'>'), $control);
        }
        $this->assertStringContainsString('el.style.zoom', $html);
    }
}
