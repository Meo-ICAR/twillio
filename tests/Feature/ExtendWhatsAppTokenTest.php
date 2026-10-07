<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtendWhatsAppTokenTest extends TestCase
{
    private ?string $envFile = null;

    /** Un .env finto: i test non devono mai leggere i segreti veri. */
    private function fakeEnv(string $content): void
    {
        $dir = storage_path('framework/testing');
        File::ensureDirectoryExists($dir);
        $this->envFile = 'env-'.uniqid();
        file_put_contents("{$dir}/{$this->envFile}", $content);
        $this->app->useEnvironmentPath($dir);
        $this->app->loadEnvironmentFrom($this->envFile);
    }

    protected function tearDown(): void
    {
        $this->envFile && File::delete(storage_path('framework/testing/'.$this->envFile));
        parent::tearDown();
    }

    public function test_legge_id_e_chiave_dal_file_env_anche_con_la_configurazione_vuota(): void
    {
        config(['services.whatsapp.app_id' => null, 'services.whatsapp.app_secret' => null]);
        $this->fakeEnv("META_APP_ID=999\nMETA_APP_SECRET=\"DAL-FILE\"\n");
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'LUNGO', 'expires_in' => 5184000])]);

        $this->artisan('whatsapp:extend-token BREVE')->doesntExpectOutputToContain('DAL-FILE')->assertSuccessful();

        Http::assertSent(fn ($r) => $r['client_id'] === '999' && $r['client_secret'] === 'DAL-FILE');
    }

    public function test_se_nel_file_env_mancano_usa_la_configurazione(): void
    {
        $this->fakeEnv("ALTRO=1\n");
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'DA-CONFIG']);
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'LUNGO'])]);

        $this->artisan('whatsapp:extend-token BREVE')->assertSuccessful();

        Http::assertSent(fn ($r) => $r['client_secret'] === 'DA-CONFIG');
    }

    public function test_senza_id_e_chiave_dell_app_non_fa_nulla(): void
    {
        $this->fakeEnv("ALTRO=1\n");
        config(['services.whatsapp.app_id' => null, 'services.whatsapp.app_secret' => null]);
        Http::fake();

        $this->artisan('whatsapp:extend-token breve')->expectsOutputToContain('META_APP_ID')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_scambia_il_token_e_lo_mostra_senza_mostrare_la_chiave(): void
    {
        $this->fakeEnv("ALTRO=1\n");
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
        $this->fakeEnv("ALTRO=1\n");
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'S']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token']], 400)]);

        $this->artisan('whatsapp:extend-token VECCHIO')
            ->expectsOutputToContain('Error validating access token')
            ->doesntExpectOutputToContain('Token (da incollare')
            ->assertFailed();
    }

    public function test_il_token_breve_si_puo_dare_di_nascosto(): void
    {
        $this->fakeEnv("ALTRO=1\n");
        config(['services.whatsapp.app_id' => '123', 'services.whatsapp.app_secret' => 'S']);
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'LUNGO'])]);

        $this->artisan('whatsapp:extend-token')->expectsQuestion('Token a breve durata', 'BREVE')
            ->expectsOutputToContain('Meta non indica la scadenza')->assertSuccessful();
    }
}
