<?php

namespace Tests\Feature;

use App\Services\Conversation\Reply;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
    }

    public function test_invia_un_testo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $this->assertTrue((new WhatsAppClient)->send('3933', Reply::text('Ciao')));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v20.0/555/messages'
            && $r->hasHeader('Authorization', 'Bearer TOK')
            && $r['to'] === '3933' && $r['type'] === 'text' && $r['text']['body'] === 'Ciao');
    }

    public function test_invia_pulsanti(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);

        (new WhatsAppClient)->send('3933', Reply::choice('Confermi?', ['si' => 'Sì', 'no' => 'No']));

        Http::assertSent(fn (Request $r) => $r['interactive']['type'] === 'button'
            && $r['interactive']['action']['buttons'][0]['reply'] === ['id' => 'si', 'title' => 'Sì']
            && count($r['interactive']['action']['buttons']) === 2);
    }

    public function test_invia_lista_e_tronca_i_titoli_oltre_i_limiti(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);
        $long = str_repeat('x', 40);

        (new WhatsAppClient)->send('3933', Reply::choice(str_repeat('b', 2000), ['a' => $long, 'b' => 'B', 'c' => 'C', 'd' => 'D']));

        Http::assertSent(function (Request $r) {
            $rows = $r['interactive']['action']['sections'][0]['rows'];

            return $r['interactive']['type'] === 'list'
                && mb_strlen($rows[0]['title']) === 24
                && mb_strlen($r['interactive']['body']['text']) === 1024
                && $r['interactive']['action']['button'] === 'Scegli'
                && count($rows) === 4;
        });
    }

    public function test_un_errore_di_meta_restituisce_false(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no']], 400)]);

        $this->assertFalse((new WhatsAppClient)->send('3933', Reply::text('Ciao')));
    }

    public function test_scarica_un_media(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/M1' => Http::response(['url' => 'https://lookaside.fbsbx.com/f', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('BYTES'),
        ]);

        $this->assertSame(['body' => 'BYTES', 'mime' => 'image/jpeg'], (new WhatsAppClient)->downloadMedia('M1'));
    }

    public function test_download_fallito_restituisce_null(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 404)]);

        $this->assertNull((new WhatsAppClient)->downloadMedia('M1'));
    }
}
