<?php

namespace Tests\Unit;

use App\Services\Conversation\SensitiveDataGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SensitiveDataGuardTest extends TestCase
{
    public static function identificativi(): array
    {
        return [
            'codice fiscale' => ['RSSMRA80A01H501U'],
            'codice fiscale minuscolo' => ['rssmra80a01h501u'],
            'codice fiscale in frase' => ['il cliente è rssmra80a01h501u ok'],
            'email' => ['mario.rossi@example.com'],
            'cellulare con spazi' => ['333 123 4567'],
            'cellulare internazionale' => ['+39 333 1234567'],
            'fisso con trattino' => ['02-1234567890'],
            'partita iva' => ['12345678901'],
        ];
    }

    public static function innocui(): array
    {
        return [
            'scelta' => ['dipendente privato'],
            'importo' => ['1500'],
            'fascia' => ['Fino a 5.000 €'],
            'anni' => ['12 anni'],
            'numero e testo' => ['60 mesi'],
        ];
    }

    #[DataProvider('identificativi')]
    public function test_riconosce_dati_identificativi(string $text): void
    {
        $this->assertTrue((new SensitiveDataGuard)->containsIdentifyingData($text));
    }

    #[DataProvider('innocui')]
    public function test_lascia_passare_dati_di_profilo(string $text): void
    {
        $this->assertFalse((new SensitiveDataGuard)->containsIdentifyingData($text));
    }
}
