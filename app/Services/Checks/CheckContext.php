<?php

namespace App\Services\Checks;

/** Ciò che un controllo può leggere (dati già raccolti, parametri) e scrivere (dati ricavati, errore). */
final class CheckContext
{
    /** @var array<string,string> */
    private array $derived = [];

    private ?string $error = null;

    /**
     * @param  array<string,mixed>  $data  dati già raccolti nel dialogo
     * @param  array<string,mixed>  $params  parametri con cui il controllo è agganciato alla domanda
     */
    public function __construct(private array $data, private array $params = []) {}

    /** @param array<string,mixed> $params */
    public function withParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    /** Un dato del dialogo, compresi quelli ricavati in questo stesso passaggio. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->derived[$key] ?? $this->data[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->derived[$key] = $value;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Segna il controllo come fallito con un messaggio per l'agente. Restituisce false, per scrivere `return $ctx->fail('...')`. */
    public function fail(string $message): bool
    {
        $this->error = $message;

        return false;
    }

    /** @return array<string,string> */
    public function derived(): array
    {
        return $this->derived;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
