<?php

namespace App\Services\Checks;

use App\Models\FlowCheck;
use Illuminate\Support\Facades\Schema;

/**
 * I controlli disponibili per le domande. L'elenco sta nella tabella flow_checks; se la tabella non c'è
 * o è vuota vale la configurazione ('checks' in config/finanziamento.php).
 */
class CheckRegistry
{
    /** @var array<string,string>|null codice => classe, solo controlli attivi, in ordine */
    private ?array $classes = null;

    /** @var array<string,Check> controlli aggiunti a runtime con extend() */
    private array $extensions = [];

    public function get(string $name): ?Check
    {
        if (isset($this->extensions[$name])) {
            return $this->extensions[$name];
        }

        $class = $this->classes()[$name] ?? null;

        return $class && class_exists($class) ? app($class) : null;
    }

    /** @return array<string,Check> codice => controllo, solo gli attivi */
    public function all(): array
    {
        $checks = [];
        foreach (array_keys($this->classes()) as $name) {
            if ($check = $this->get($name)) {
                $checks[$name] = $check;
            }
        }

        return $checks + $this->extensions;
    }

    /** Aggiunge un controllo senza passare dalla tabella (utile per pacchetti e prove). */
    public function extend(string $name, Check $check): void
    {
        $this->extensions[$name] = $check;
    }

    /** @return array<string,NodeCheck> i controlli sulle risposte */
    public function nodeChecks(): array
    {
        return array_filter($this->all(), fn (Check $c) => $c instanceof NodeCheck);
    }

    /** @return array<string,DocumentCheck> i controlli sui documenti */
    public function documentChecks(): array
    {
        return array_filter($this->all(), fn (Check $c) => $c instanceof DocumentCheck);
    }

    public function forget(): void
    {
        $this->classes = null;
    }

    /**
     * Classi che implementano NodeCheck o DocumentCheck nella cartella dei controlli.
     *
     * @return list<class-string<Check>>
     */
    public function discover(): array
    {
        $found = [];
        foreach (glob(app_path('Services/Checks/*.php')) as $file) {
            $class = 'App\\Services\\Checks\\'.basename($file, '.php');
            if (class_exists($class) && (new \ReflectionClass($class))->isInstantiable() && is_subclass_of($class, Check::class)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * Aggiunge all'elenco i controlli trovati nella cartella che non ci sono ancora.
     * Non modifica né riattiva quelli esistenti.
     *
     * @return int controlli aggiunti
     */
    public function sync(): int
    {
        $added = 0;
        $order = (int) FlowCheck::max('sort_order');

        foreach ($this->discover() as $class) {
            if (FlowCheck::where('class', $class)->exists() || FlowCheck::where('code', FlowCheck::codeFor($class))->exists()) {
                continue;
            }

            FlowCheck::create(['code' => FlowCheck::codeFor($class), 'class' => $class, 'is_active' => true, 'sort_order' => ++$order]);
            $added++;
        }

        return $added;
    }

    /** @return array<string,string> */
    private function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        if (! Schema::hasTable('flow_checks') || FlowCheck::query()->doesntExist()) {
            return $this->classes = config('finanziamento.checks', []);
        }

        return $this->classes = FlowCheck::where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('class', 'code')->all();
    }
}
