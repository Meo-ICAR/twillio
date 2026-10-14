<?php

namespace Tests\Unit;

use App\Services\Conversation\PartitaIva;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PartitaIvaTest extends TestCase
{
    public static function validi(): array
    {
        return [
            'esempio classico' => ['12345678903'],
            'con spazi' => ['123 456 789 03'],
            'con prefisso IT' => ['IT12345678903'],
            'altra valida' => ['01234567897'],
        ];
    }

    public static function non_validi(): array
    {
        return [
            'checksum errato' => ['12345678904'],
            'troppo corta' => ['1234567890'],
            'troppo lunga' => ['123456789031'],
            'lettere' => ['1234567890A'],
            'tutti zeri' => ['00000000000'],
            'vuota' => [''],
        ];
    }

    #[DataProvider('validi')]
    public function test_partite_iva_valide(string $value): void
    {
        $this->assertTrue(PartitaIva::isValid($value));
    }

    #[DataProvider('non_validi')]
    public function test_partite_iva_non_valide(string $value): void
    {
        $this->assertFalse(PartitaIva::isValid($value));
    }
}
