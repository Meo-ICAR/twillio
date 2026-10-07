<?php

namespace App\Services\Conversation;

use App\Services\Flows\FlowRepository;

/** Disegna gli alberi di config/finanziamento.php come diagrammi Mermaid o pagina HTML. */
class FlowGraph
{
    private const TITLES = [
        'richiesta' => 'Richiedi Finanziamento (fase 1, anonima)',
        'perfezionamento' => 'Perfeziona Finanziamento (fase 2, dopo l\'informativa)',
        'documenti' => 'Stato Pratiche → Carica documenti (anche in giorni diversi)',
    ];

    private const SHAPES = [
        'choice' => ['[', ']'],
        'text' => ['[/', '/]'],
        'file' => ['[[', ']]'],
        'code' => ['[[', ']]'],
        'summary' => ['([', '])'],
        'check' => ['{', '}'],
    ];

    public function __construct(private ?FlowRepository $flows = null)
    {
        $this->flows ??= app(FlowRepository::class);
    }

    public function mermaid(string $flow): string
    {
        $nodes = $this->flows->flow($flow)['nodes'];
        $lines = ['flowchart TD'];
        $byType = [];

        foreach ($nodes as $name => $node) {
            [$open, $close] = self::SHAPES[$node['type']];
            $text = $node['type'] === 'summary' ? 'Riepilogo e conferma' : $node['prompt'];
            $lines[] = "    {$name}{$open}\"".$this->escape($this->shorten($text)).'"'.$close;
            $byType[$node['type']][] = $name;
        }

        foreach ($nodes as $name => $node) {
            foreach ($this->transitions($node) as $target => $label) {
                $lines[] = $label === ''
                    ? "    {$name} --> {$target}"
                    : "    {$name} -->|\"".$this->escape($label)."\"| {$target}";
            }
        }

        $lines[] = '    classDef choice fill:#e8f3ff,stroke:#2b6cb0,color:#12263f';
        $lines[] = '    classDef text fill:#fff4e0,stroke:#c77700,color:#3d2600';
        $lines[] = '    classDef file fill:#ffe8ec,stroke:#c0392b,color:#3d0e08';
        $lines[] = '    classDef code fill:#ffe8ec,stroke:#c0392b,color:#3d0e08';
        $lines[] = '    classDef summary fill:#e6f6ea,stroke:#2f855a,color:#0f2d1a';
        $lines[] = '    classDef check fill:#f0e9ff,stroke:#6b46c1,color:#2a1a52';
        foreach ($byType as $type => $names) {
            $lines[] = '    class '.implode(',', $names).' '.$type;
        }

        return implode("\n", $lines)."\n";
    }

    public function html(): string
    {
        $sections = '';
        $first = true;
        foreach (self::TITLES as $flow => $title) {
            if (! $this->flows->flow($flow)) {
                continue;
            }
            $count = count($this->flows->flow($flow)['nodes']);
            // Il primo diagramma è molto grande: parte al 30% per mostrarlo intero.
            $zoom = $first ? '0.3' : '1';
            $first = false;
            $sections .= '<section data-zoom="'.$zoom.'"><h2>'.htmlspecialchars($title)."</h2><p class=\"meta\">{$count} domande</p>"
                .'<div class="tools"><button type="button" data-zoom-out>−</button><button type="button" data-zoom-in>+</button>'
                .'<button type="button" data-zoom-reset>100%</button><span class="level"></span></div>'
                .'<div class="scroll"><pre class="mermaid">'.htmlspecialchars($this->mermaid($flow)).'</pre></div></section>';
        }

        return <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grafo delle domande del bot</title>
<style>
  :root { color-scheme: light dark; --bg:#f7f8fa; --fg:#1b2430; --muted:#5b6675; --card:#fff; --line:#dde2e8; }
  @media (prefers-color-scheme: dark) { :root { --bg:#10151c; --fg:#e6ebf1; --muted:#9aa6b5; --card:#18202a; --line:#2a3542; } }
  body { margin:0; padding:24px 16px 48px; background:var(--bg); color:var(--fg); font:16px/1.5 system-ui, sans-serif; }
  main { max-width:1200px; margin:0 auto; }
  h1 { margin:0 0 4px; font-size:1.6rem; }
  h2 { margin:32px 0 4px; font-size:1.2rem; }
  .meta, .legend { color:var(--muted); margin:0 0 12px; font-size:.9rem; }
  .scroll { overflow:auto; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; }
  pre.mermaid { margin:0; text-align:center; }
  .tools { display:flex; align-items:center; gap:6px; margin:0 0 8px; }
  .tools button { border:1px solid var(--line); background:var(--card); color:var(--fg); border-radius:8px; padding:4px 12px; font:inherit; cursor:pointer; }
  .tools .level { color:var(--muted); font-size:.9rem; margin-left:8px; }
  a { color:#2b6cb0; }
</style>
</head>
<body>
<main>
  <p class="legend"><a href="/">← Torna alla home</a></p>
  <h1>Grafo delle domande del bot</h1>
  <p class="legend">Rettangolo = scelta · parallelogramma = testo libero · doppio bordo = file o codice · ovale = riepilogo e conferma. Generato da <code>config/finanziamento.php</code> con <code>php artisan finanziamento:graph</code>.</p>
  {$sections}
</main>
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  const dark = matchMedia('(prefers-color-scheme: dark)').matches;
  mermaid.initialize({ startOnLoad: false, theme: dark ? 'dark' : 'default', flowchart: { useMaxWidth: false, htmlLabels: true } });
  await mermaid.run();
  document.querySelectorAll('section[data-zoom]').forEach((section) => {
    const el = section.querySelector('pre.mermaid');
    const level = section.querySelector('.level');
    let zoom = parseFloat(section.dataset.zoom);
    const apply = () => { el.style.zoom = zoom; level.textContent = Math.round(zoom * 100) + '%'; };
    section.querySelector('[data-zoom-in]').addEventListener('click', () => { zoom = Math.min(zoom * 1.25, 3); apply(); });
    section.querySelector('[data-zoom-out]').addEventListener('click', () => { zoom = Math.max(zoom / 1.25, 0.1); apply(); });
    section.querySelector('[data-zoom-reset]').addEventListener('click', () => { zoom = 1; apply(); });
    apply();
  });
</script>
</body>
</html>
HTML;
    }

    /** @return array<string,string> destinazione => etichetta ('' se il salto è incondizionato) */
    private function transitions(array $node): array
    {
        if ($node['type'] === 'summary') {
            return [];
        }
        if (is_string($node['next'])) {
            return [$node['next'] => ''];
        }

        $by = $node['next_by'] ?? 'answer';
        $grouped = [];
        foreach ($node['next'] as $key => $target) {
            $grouped[$target][] = match (true) {
                $key === '*' => 'Altre risposte',
                $by === 'answer' => $node['outcomes'][$key] ?? $node['options'][$key] ?? $key,
                $by === 'prodotto' => ($this->flows->node('richiesta', 'prodotto')['options'] ?? [])[$key] ?? $key,
                default => $key,
            };
        }

        return array_map(fn (array $labels) => implode(' / ', $labels), $grouped);
    }

    private function shorten(string $text): string
    {
        return mb_strlen($text) > 70 ? rtrim(mb_substr($text, 0, 67)).'…' : $text;
    }

    private function escape(string $text): string
    {
        return str_replace(['"', "'", '<', '>', '|', "\n"], ['#quot;', '#39;', '#lt;', '#gt;', '#124;', ' '], $text);
    }
}
