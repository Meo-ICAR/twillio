<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppProfileTest extends TestCase
{
    public function test_la_configurazione_rispetta_i_limiti_di_whatsapp(): void
    {
        $profile = config('finanziamento.profile');

        $this->assertLessThanOrEqual(4, count($profile['prompts']));
        foreach ($profile['prompts'] as $p) {
            $this->assertLessThanOrEqual(80, mb_strlen($p));
        }
        foreach ($profile['commands'] as $name => $description) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $name);
            $this->assertLessThanOrEqual(256, mb_strlen($description));
        }
        foreach (['menu', 'help', 'annulla', 'indietro', 'salta', 'avanti'] as $command) {
            $this->assertArrayHasKey($command, $profile['commands']);
        }
    }

    public function test_i_messaggi_per_rompere_il_ghiaccio_sono_le_voci_del_menu(): void
    {
        $titles = array_values(config('finanziamento.menu.options'));

        foreach (array_slice(config('finanziamento.profile.prompts'), 0, 3) as $i => $prompt) {
            $this->assertSame($titles[$i], $prompt);
        }
    }

    public function test_dry_run_mostra_il_contenuto_senza_chiamare_meta(): void
    {
        Http::fake();

        $this->artisan('whatsapp:setup-profile --dry-run')
            ->expectsOutputToContain('"command_name": "menu"')
            ->expectsOutputToContain('Richiedi Finanziamento')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_invia_comandi_e_messaggi_a_meta(): void
    {
        config(['services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->artisan('whatsapp:setup-profile')->expectsOutputToContain('Profilo aggiornato: 6 comandi e 4 messaggi')->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/555/conversational_automation')
            && $r['prompts'][0] === 'Richiedi Finanziamento'
            && $r['commands'][0]['command_name'] === 'menu'
            && $r['enable_welcome_message'] === true);
    }

    public function test_senza_token_non_chiama_meta(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => '555']);
        Http::fake();

        $this->artisan('whatsapp:setup-profile')->expectsOutputToContain('META_WA_TOKEN')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_segnala_il_rifiuto_di_meta(): void
    {
        config(['services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401)]);
        $this->artisan('whatsapp:setup-profile')->expectsOutputToContain('Invalid OAuth access token')->assertFailed();
    }

    public function test_rifiuta_una_configurazione_fuori_dai_limiti(): void
    {
        config(['finanziamento.profile.prompts' => ['a', 'b', 'c', 'd', 'e']]);
        Http::fake();

        $this->artisan('whatsapp:setup-profile')->expectsOutputToContain('al massimo 4')->assertFailed();

        Http::assertNothingSent();
    }
}
