<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetupWhatsAppProfile extends Command
{
    protected $signature = 'whatsapp:setup-profile {--dry-run : Mostra cosa verrebbe impostato senza chiamare Meta}';

    protected $description = 'Imposta nel profilo WhatsApp i comandi (/menu, /help…) e i messaggi per rompere il ghiaccio';

    public function handle(): int
    {
        $profile = config('finanziamento.profile');
        $payload = [
            'enable_welcome_message' => (bool) ($profile['enable_welcome_message'] ?? false),
            'commands' => collect($profile['commands'])->map(fn ($description, $name) => ['command_name' => $name, 'command_description' => $description])->values()->all(),
            'prompts' => array_values($profile['prompts']),
        ];

        if ($error = $this->problem($payload)) {
            $this->error($error);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach (explode("\n", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        $id = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');
        if (blank($id) || blank($token)) {
            $this->error('Mancano META_WA_PHONE_NUMBER_ID o META_WA_TOKEN nel .env.');

            return self::FAILURE;
        }

        try {
            $response = Http::withToken($token)->timeout(15)->post("https://graph.facebook.com/v20.0/{$id}/conversational_automation", $payload);
        } catch (\Throwable) {
            $this->error('Meta non raggiungibile.');

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('Meta ha rifiutato la richiesta: '.($response->json('error.message') ?? 'errore '.$response->status()));

            return self::FAILURE;
        }

        $this->info('Profilo aggiornato: '.count($payload['commands']).' comandi e '.count($payload['prompts']).' messaggi per rompere il ghiaccio.');

        return self::SUCCESS;
    }

    /** Limiti di WhatsApp: 4 messaggi da 80 caratteri; comandi da 32 caratteri con descrizione da 256. */
    private function problem(array $payload): ?string
    {
        if (count($payload['prompts']) > 4) {
            return 'I messaggi per rompere il ghiaccio sono al massimo 4.';
        }
        foreach ($payload['prompts'] as $prompt) {
            if (mb_strlen($prompt) > 80) {
                return "Il messaggio «{$prompt}» supera 80 caratteri.";
            }
        }
        foreach ($payload['commands'] as $command) {
            if (! preg_match('/^[a-z0-9_]{1,32}$/', $command['command_name']) || mb_strlen($command['command_description']) > 256) {
                return "Il comando «{$command['command_name']}» non rispetta i limiti di WhatsApp (nome minuscolo fino a 32 caratteri, descrizione fino a 256).";
            }
        }

        return null;
    }
}
