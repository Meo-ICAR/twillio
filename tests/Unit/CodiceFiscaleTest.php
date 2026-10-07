<?php

namespace Tests\Unit;

use App\Services\Conversation\CodiceFiscale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodiceFiscaleTest extends TestCase
{
    public function test_ricava_data_sesso_e_luogo_di_nascita(): void
    {
        $info = CodiceFiscale::parse('RSSMRA80A01H501U', 2026);

        $this->assertSame('01/01/1980', $info['birth_date']);
        $this->assertSame('M', $info['sex']);
        $this->assertSame('H501', $info['belfiore']);
        $this->assertSame('Roma (RM)', $info['place']);
    }

    public function test_per_le_donne_il_giorno_ha_40_in_piu(): void
    {
        $info = CodiceFiscale::parse('RSSMRA80A41H501Z', 2026);

        $this->assertSame('01/01/1980', $info['birth_date']);
        $this->assertSame('F', $info['sex']);
    }

    public function test_gestisce_le_omocodie(): void
    {
        $info = CodiceFiscale::parse('RSSMRA8LT01H501U', 2026);

        $this->assertSame('01/12/1980', $info['birth_date']);
        $this->assertSame('H501', $info['belfiore']);
    }

    public function test_accetta_minuscole_e_spazi(): void
    {
        $this->assertSame('01/01/1980', CodiceFiscale::parse('rssmra 80a01 h501u', 2026)['birth_date']);
    }

    public function test_il_secolo_si_sceglie_in_base_all_anno_di_riferimento(): void
    {
        $this->assertSame('01/01/2008', CodiceFiscale::parse('RSSMRA08A01H501U', 2026)['birth_date']);
        $this->assertSame('01/01/1930', CodiceFiscale::parse('RSSMRA30A01H501U', 2026)['birth_date']);
    }

    public function test_luogo_sconosciuto_o_estero_resta_nullo(): void
    {
        $this->assertNull(CodiceFiscale::parse('RSSMRA80A01Z404U', 2026)['place']);
    }

    #[DataProvider('non_validi')]
    public function test_rifiuta_codici_non_interpretabili(string $cf): void
    {
        $this->assertNull(CodiceFiscale::parse($cf, 2026));
    }

    public static function non_validi(): array
    {
        return [
            'mese inesistente' => ['RSSMRA80Z01H501U'],
            'giorno impossibile' => ['RSSMRA80B31H501U'],
            'troppo corto' => ['RSSMRA80A01H501'],
            'cifre nei primi sei' => ['RS1MRA80A01H501U'],
            'vuoto' => [''],
        ];
    }

    #[DataProvider('cognomi')]
    public function test_codice_del_cognome(string $surname, string $expected): void
    {
        $this->assertSame($expected, CodiceFiscale::surnameCode($surname));
    }

    public static function cognomi(): array
    {
        return [
            'tre consonanti' => ['Rossi', 'RSS'],
            'meno di tre consonanti' => ['Bo', 'BOX'],
            'spazi' => ['De Luca', 'DLC'],
            'apostrofo' => ['D\'Angelo', 'DNG'],
            'molto corto' => ['Fo', 'FOX'],
            'accenti' => ['Rè', 'REX'],
        ];
    }

    #[DataProvider('nomi')]
    public function test_codice_del_nome(string $name, string $expected): void
    {
        $this->assertSame($expected, CodiceFiscale::nameCode($name));
    }

    public static function nomi(): array
    {
        return [
            'due consonanti e vocali' => ['Mario', 'MRA'],
            'quattro o più consonanti' => ['Gianfranco', 'GFR'],
            'tre consonanti' => ['Pietro', 'PTR'],
            'due consonanti' => ['Luca', 'LCU'],
            'una consonante' => ['Al', 'LAX'],
            'accento finale' => ['Nicolò', 'NCL'],
        ];
    }

    public function test_segnala_cognome_e_nome_non_coerenti_con_il_codice(): void
    {
        $this->assertSame([], CodiceFiscale::mismatches('RSSMRA80A01H501U', 'Rossi', 'Mario'));

        $this->assertSame(
            [['field' => 'cognome', 'expected' => 'BNC', 'found' => 'RSS']],
            CodiceFiscale::mismatches('RSSMRA80A01H501U', 'Bianchi', 'Mario')
        );
        $this->assertSame(
            [['field' => 'cognome', 'expected' => 'BNC', 'found' => 'RSS'], ['field' => 'nome', 'expected' => 'LCU', 'found' => 'MRA']],
            CodiceFiscale::mismatches('RSSMRA80A01H501U', 'Bianchi', 'Luca')
        );
    }
}
