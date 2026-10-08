<?php

namespace Tests\Feature\Loans;

use App\Services\Loans\Mediafacile\MediafacileClient;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileClientTest extends TestCase
{
    private const XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Offerte>
  <Offerta><ID>1</ID><Importo_erogato>12.345,67</Importo_erogato><Errore>2</Errore><Stato></Stato></Offerta>
  <Offerta><ID>2</ID><Importo_erogato>10000.50</Importo_erogato><Errore>2</Errore></Offerta>
  <Offerta><ID>3</ID><Importo_erogato>0</Importo_erogato><Errore>1</Errore><Stato>Importo fuori limiti</Stato></Offerta>
</Offerte>
XML;

    public function test_legge_gli_importi_nei_due_formati_e_distingue_le_offerte_valide(): void
    {
        $this->assertSame([
            ['valid' => true, 'erogato' => 12345.67],
            ['valid' => true, 'erogato' => 10000.5],
            ['valid' => false, 'erogato' => 0.0],
        ], MediafacileClient::parse(self::XML));
    }

    public function test_una_risposta_piu_articolata_si_legge_lo_stesso(): void
    {
        $xml = '<Risposta><Esito>OK</Esito><Preventivo><Codice_preventivo>P1</Codice_preventivo><Offerte>'
            .'<Offerta><Istituto><Nome>Banca X</Nome></Istituto><Importo_rata>400,00</Importo_rata><Importo_provvigione>50,00</Importo_provvigione>'
            .'<Importo_erogato>20.000,00</Importo_erogato><Tan>5,5</Tan><Errore>2</Errore></Offerta>'
            .'</Offerte></Preventivo></Risposta>';

        $this->assertSame([['valid' => true, 'erogato' => 20000.0]], MediafacileClient::parse($xml));
    }

    public function test_un_xml_non_valido_o_senza_offerte(): void
    {
        $this->assertSame([], MediafacileClient::parse('<Offerte/>'));

        $this->expectException(QuoteUnavailable::class);
        MediafacileClient::parse('questo non è xml');
    }

    public function test_invia_passkey_e_parametri_nell_url_in_post_e_restituisce_il_corpo(): void
    {
        Http::fake(['crm.example.com/*' => Http::response(self::XML)]);

        $body = (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', ['Tipo_contratto' => 'Cessione', 'Durata' => '60']);

        $this->assertSame(self::XML, $body);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $r->method() === 'POST'
                && str_starts_with($r->url(), 'https://crm.example.com/ws/offerte?')
                && $query === ['Passkey' => 'KEY', 'Tipo_contratto' => 'Cessione', 'Durata' => '60'];
        });
    }

    public function test_un_errore_http_o_di_connessione_diventa_quote_unavailable(): void
    {
        Http::fake(['crm.example.com/*' => Http::response('boom', 500)]);
        try {
            (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', []);
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            $this->addToAssertionCount(1);
        }

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->expectException(QuoteUnavailable::class);
        (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', []);
    }
}
