<?php

namespace Tests\Feature\Crm;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Services\Crm\LeadParameters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LeadParametersTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $answers = []): LoanRequest
    {
        return new LoanRequest([
            'code' => 'FIN-2026-0007', 'agent_wa_number' => '393331112222', 'product' => 'personale',
            'answers' => $answers + ['prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'lavoro' => 'dip_pub'],
        ]);
    }

    private function personal(array $override = []): array
    {
        return $override + [
            'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'residenza' => 'Via Roma 1, 20100, Milano',
            'telefono' => '+393331234567', 'email' => 'mario@example.com',
            'codice_fiscale' => 'RSSMRA80A01H501U', 'iban' => 'IT60X0542811101000000123456', 'documento_numero' => 'AB123456',
        ];
    }

    public function test_i_parametri_del_servizio(): void
    {
        Fornitore::create(['name' => 'Mario', 'tel' => '393331112222', 'sigla' => 'PM', 'is_active' => true]);

        $p = LeadParameters::build($this->loan(), $this->personal());

        $this->assertSame([
            'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01-01-1980', 'tipologia' => 'Pubblico',
            'importo_richiesto' => '10000,00', 'residenza_citta' => 'Milano', 'residenza_provincia' => 'MI',
            'cellulare' => '+393331234567', 'email' => 'mario@example.com', 'fonte' => 'unicoagent',
        ], Arr::except($p, 'annotazioni'));
        $this->assertArrayNotHasKey('file', $p);
        $this->assertArrayNotHasKey('Passkey', $p);
    }

    public function test_le_annotazioni_riassumono_la_pratica_senza_dati_sensibili(): void
    {
        Fornitore::create(['name' => 'Mario', 'tel' => '393331112222', 'sigla' => 'PM', 'is_active' => true]);

        $note = LeadParameters::build($this->loan(), $this->personal())['annotazioni'];

        foreach (['FIN-2026-0007', 'Prestito personale', '36 mesi', '5.000 - 10.000 €', 'PM'] as $part) {
            $this->assertStringContainsString($part, $note);
        }
        foreach (['IT60X0542811101000000123456', 'RSSMRA80A01H501U', 'AB123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $note);
        }
    }

    public function test_la_tipologia_segue_le_risposte(): void
    {
        $this->assertSame('Privato', LeadParameters::build($this->loan(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'oltre15']), $this->personal())['tipologia']);
        $this->assertSame('Pensionato INPS', LeadParameters::build($this->loan(['lavoro' => 'pensionato', 'ente_pensione' => 'inps']), $this->personal())['tipologia']);
        $this->assertSame('Pensionato altri enti', LeadParameters::build($this->loan(['lavoro' => 'pensionato', 'ente_pensione' => 'exinpdap']), $this->personal())['tipologia']);
    }

    public function test_citta_e_provincia_vengono_dalla_residenza(): void
    {
        $p = LeadParameters::build($this->loan(), $this->personal(['residenza' => 'Via Garibaldi 5, 20097, San Donato Milanese']));
        $this->assertSame(['San Donato Milanese', 'MI'], [$p['residenza_citta'], $p['residenza_provincia']]);

        $p = LeadParameters::build($this->loan(), $this->personal(['residenza' => 'Via X 1, Castro']));
        $this->assertSame('Castro', $p['residenza_citta']);
        $this->assertArrayNotHasKey('residenza_provincia', $p, 'omonimo senza sigla: nessuna provincia inventata');
    }

    public function test_una_data_non_valida_o_assente_si_omette(): void
    {
        $this->assertArrayNotHasKey('data_nascita', LeadParameters::build($this->loan(), $this->personal(['data_nascita' => '31/02/1980'])));
        $this->assertArrayNotHasKey('data_nascita', LeadParameters::build($this->loan(), $this->personal(['data_nascita' => null])));
    }

    public function test_senza_fascia_di_importo_o_tipologia_i_parametri_si_omettono(): void
    {
        $p = LeadParameters::build($this->loan(['importo' => 'imp_vecchia', 'lavoro' => 'sconosciuto']), $this->personal());

        $this->assertArrayNotHasKey('importo_richiesto', $p);
        $this->assertArrayNotHasKey('tipologia', $p);
    }
}
