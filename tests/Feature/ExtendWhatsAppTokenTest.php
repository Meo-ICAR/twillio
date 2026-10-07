<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtendWhatsAppTokenTest extends TestCase
{
    public function test_senza_id_e_chiave_dell_app_non_fa_nulla(): void
    {
        config(['services.whatsapp.app_id' => null, 'services.whatsapp.app_secret' => null]);
        Http::fake();

        $this->artisan('whatsapp:extend-token breve')->expectsOutputToContain('META_APP_ID')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_scambia_il_token_e_lo_mostra_senza_mostrare_la_chiave(): void
    {
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'CHIAVE-SEGRETA']);
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'TOKEN-LUNGO', 'expires_in' => 5184000])]);

        $this->artisan('whatsapp:extend-token TOKEN-BREVE')
            ->expectsOutputToContain('TOKEN-LUNGO')
            ->expectsOutputToContain('60 giorni')
            ->doesntExpectOutputToContain('CHIAVE-SEGRETA')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => $r['grant_type'] === 'fb_exchange_token' && $r['client_id'] === '123'
            && $r['client_secret'] === 'CHIAVE-SEGRETA' && $r['fb_exchange_token'] === 'TOKEN-BREVE');
    }

    public function test_se_meta_rifiuta_mostra_il_motivo_e_nessun_token(): void
    {
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'S']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token']], 400)]);

        $this->artisan('whatsapp:extend-token VECCHIO')
            ->expectsOutputToContain('Error validating access token')
            ->doesntExpectOutputToContain('Token (da incollare')
            ->assertFailed();
    }

    public function test_il_token_breve_si_puo_dare_di_nascosto(): void
    {
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'S']);
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'LUNGO'])]);

        $this->artisan('whatsapp:extend-token')->expectsQuestion('Token a breve durata', 'BREVE')
            ->expectsOutputToContain('Meta non indica la scadenza')->assertSuccessful();
    }
}
