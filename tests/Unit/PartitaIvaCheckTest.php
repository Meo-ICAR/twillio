<?php

namespace Tests\Unit;

use App\Services\Checks\CheckContext;
use App\Services\Checks\PartitaIvaCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Il controllo normalizza quello che scrive l'agente e delega la verifica alla routine di unico-core. */
class PartitaIvaCheckTest extends TestCase
{
    public static function valide(): array
    {
        return ['semplice' => ['12345678903'], 'con spazi' => ['123 456 789 03'], 'con prefisso IT' => ['IT12345678903'], 'minuscolo' => ['it12345678903']];
    }

    public static function non_valide(): array
    {
        return ['checksum errato' => ['12345678904'], 'troppo corta' => ['1234567890'], 'lettere' => ['1234567890A'], 'soli zeri' => ['00000000000'], 'vuota' => ['']];
    }

    #[DataProvider('valide')]
    public function test_accetta_le_partite_iva_valide(string $value): void
    {
        $this->assertTrue((new PartitaIvaCheck)->passes($value, new CheckContext([])));
    }

    #[DataProvider('non_valide')]
    public function test_rifiuta_le_partite_iva_non_valide(string $value): void
    {
        $this->assertFalse((new PartitaIvaCheck)->passes($value, new CheckContext([])));
    }
}
