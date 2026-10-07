<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ExtendWhatsAppToken extends Command
{
    protected $signature = 'whatsapp:extend-token {token? : Token utente a breve durata (se manca viene chiesto, senza mostrarlo)}';

    protected $description = 'Scambia un token Meta a breve durata con uno da circa 60 giorni e lo mostra (non scrive nel .env)';

    public function handle(): int
    {
        $appId = config('services.whatsapp.app_id');
        $secret = config('services.whatsapp.app_secret');
        if (blank($appId) || blank($secret)) {
            $this->error('Mancano META_APP_ID e META_APP_SECRET nel .env (ID app e chiave segreta dalle impostazioni dell\'app Meta).');

            return self::FAILURE;
        }

        $short = $this->argument('token') ?: $this->secret('Token a breve durata');
        if (blank($short)) {
            $this->error('Serve il token a breve durata.');

            return self::FAILURE;
        }

        try {
            $response = Http::timeout(15)->get('https://graph.facebook.com/v20.0/oauth/access_token', [
                'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $short,
            ]);
        } catch (\Throwable) {
            $this->error('Meta non raggiungibile.');

            return self::FAILURE;
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            $this->error('Scambio non riuscito: '.($response->json('error.message') ?? 'errore '.$response->status()));

            return self::FAILURE;
        }

        $days = $response->json('expires_in') ? (int) round($response->json('expires_in') / 86400) : null;
        $this->line('');
        $this->line('Token (da incollare in META_WA_TOKEN nel .env):');
        $this->line($response->json('access_token'));
        $this->line('');
        $this->info($days ? "Valido circa {$days} giorni." : 'Meta non indica la scadenza.');
        $this->comment('Dopo averlo scritto nel .env: php artisan config:clear, poi «Verifica WhatsApp» nella dashboard.');

        return self::SUCCESS;
    }
}
