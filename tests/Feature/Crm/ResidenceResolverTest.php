<?php

namespace Tests\Feature\Crm;

use App\Services\Crm\ResidenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResidenceResolverTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_ricava_citta_e_provincia(string $address, string $city, ?string $province): void
    {
        $this->assertSame(['city' => $city, 'province' => $province], ResidenceResolver::resolve($address));
    }

    public static function addresses(): array
    {
        return [
            'con virgole' => ['Via Roma 1, 20100, Milano', 'Milano', 'MI'],
            'senza virgole' => ['Via Roma 1 20100 Milano', 'Milano', 'MI'],
            'nome di più parole' => ['Via Garibaldi 5, 20097, San Donato Milanese', 'San Donato Milanese', 'MI'],
            'accenti e maiuscole' => ['via dante 2, 47100, FORLI', 'Forlì', 'FC'],
            'sigla tra parentesi' => ['Via X 1, 00100, Roma (RM)', 'Roma', 'RM'],
            'sigla dopo il nome' => ['Via X 1, Roma rm', 'Roma', 'RM'],
            'alias' => ['Via X 1, 42100, Reggio Emilia', "Reggio nell'Emilia", 'RE'],
            'omonimo senza sigla' => ['Via X 1, Castro', 'Castro', null],
            'omonimo con sigla' => ['Via X 1, Castro (LE)', 'Castro', 'LE'],
            'sigla non di quel comune' => ['Via X 1, Milano (RM)', 'Milano', 'MI'],
            'comune sconosciuto' => ['Via X 1, 99999, Cittàinventata', 'Cittàinventata', null],
            'solo via' => ['Via Roma 1', 'Via Roma 1', null],
            'vuoto' => ['', '', null],
        ];
    }
}
