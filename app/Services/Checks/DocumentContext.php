<?php

namespace App\Services\Checks;

use App\Models\Attachment;
use App\Models\LoanRequest;

/** Ciò che un controllo su un documento può leggere: il documento, la pratica, i dati già noti e i campi letti dall'AI. */
final class DocumentContext
{
    /** @var array<string,mixed>|null */
    private ?array $fields = null;

    private bool $loaded = false;

    /**
     * @param  string  $readerKind  tipo di lettura: documento_identita, codice_fiscale, reddito, informativa
     * @param  \Closure():(array<string,mixed>|null)  $loader  legge il documento (chiamata all'AI)
     * @param  array<string,mixed>  $declared  dati già noti sulla pratica (dichiarati o confermati)
     * @param  array<string,mixed>  $params  parametri con cui il controllo è agganciato
     */
    public function __construct(
        public readonly Attachment $attachment,
        public readonly LoanRequest $loan,
        public readonly string $readerKind,
        private \Closure $loader,
        private array $declared = [],
        private array $params = [],
    ) {}

    /** @return array<string,mixed>|null null se il documento non si è potuto leggere. Si legge una volta sola. */
    public function fields(): ?array
    {
        if (! $this->loaded) {
            $this->fields = ($this->loader)();
            $this->loaded = true;
        }

        return $this->fields;
    }

    /** @return array<string,mixed> */
    public function declared(): array
    {
        return $this->declared;
    }

    /** @param array<string,mixed> $params */
    public function withParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }
}
