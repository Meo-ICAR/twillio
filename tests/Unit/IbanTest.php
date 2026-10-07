<?php

namespace Tests\Unit;

use App\Services\Conversation\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IbanTest extends TestCase
{
    public static function validi(): array
    {
        return [
            'italiano' => ['IT60X0542811101000000123456'],
            'minuscolo con spazi' => ['it60 x054 2811 1010 0000 0123 456'],
            'tedesco' => ['DE89370400440532013000'],
        ];
    }

    public static function non_validi(): array
    {
        return [
            'checksum errato' => ['IT61X0542811101000000123456'],
            'una cifra cambiata' => ['IT60X0542811101000000123457'],
            'troppo corto' => ['IT60X054281110100000012345'],
            'vuoto' => [''],
            'caratteri non validi' => ['IT60X05428111010000001234!6'],
        ];
    }

    #[DataProvider('validi')]
    public function test_accetta_iban_con_checksum_corretto(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban));
    }

    #[DataProvider('non_validi')]
    public function test_rifiuta_iban_non_validi(string $iban): void
    {
        $this->assertFalse(Iban::isValid($iban));
    }
}
