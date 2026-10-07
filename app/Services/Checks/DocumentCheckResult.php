<?php

namespace App\Services\Checks;

final class DocumentCheckResult
{
    /**
     * @param  list<string>  $issues  problemi trovati, in italiano, per l'agente e l'istruttore
     * @param  array<string,string>  $proposals  dati letti dal documento, con i nomi usati nel dialogo (da far confermare)
     */
    private function __construct(public readonly array $issues, public readonly array $proposals) {}

    /** @param array<string,string> $proposals */
    public static function ok(array $proposals = []): self
    {
        return new self([], $proposals);
    }

    /** @param list<string> $issues */
    public static function fail(array $issues): self
    {
        return new self($issues, []);
    }

    public function passed(): bool
    {
        return $this->issues === [];
    }
}
