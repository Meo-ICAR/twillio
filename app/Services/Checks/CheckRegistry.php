<?php

namespace App\Services\Checks;

/** I controlli disponibili, registrati per nome in config/finanziamento.php. */
class CheckRegistry
{
    public function get(string $name): ?NodeCheck
    {
        $class = config("finanziamento.checks.{$name}");

        return $class ? app($class) : null;
    }

    /** @return array<string,NodeCheck> nome => controllo */
    public function all(): array
    {
        $checks = [];
        foreach (array_keys(config('finanziamento.checks', [])) as $name) {
            $checks[$name] = $this->get($name);
        }

        return $checks;
    }
}
