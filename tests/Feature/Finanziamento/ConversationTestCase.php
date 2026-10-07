<?php

namespace Tests\Feature\Finanziamento;

use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ConversationTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $agent = '393331112222';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Senza CRM configurato i dati vanno per email all'istruttoria: nei test la posta è finta e la casella c'è.
        Mail::fake();
        config(['finanziamento.mail.to' => 'istruttoria@example.com']);
        // Tutti i test del dialogo girano sull'albero letto dal database (importato dalla configurazione).
        $this->seed(FlowSeeder::class);
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake([
            'graph.facebook.com/v20.0/FAIL' => Http::response([], 500),
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/f', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('BYTES'),
        ]);
    }

    /**
     * Invia in sequenza gli input e restituisce i Reply dell'ultimo.
     * Convenzioni: "#id" = scelta da pulsante/lista, "media:ID:mime" = allegato (l'id `FAIL` simula un download fallito), altro = testo.
     */
    protected function say(string ...$inputs): array
    {
        $engine = app(ConversationEngine::class);
        $replies = [];
        foreach ($inputs as $input) {
            $replies = $engine->handle($this->incoming($input));
        }

        return $replies;
    }

    protected function incoming(string $input): IncomingMessage
    {
        if (str_starts_with($input, '#')) {
            return new IncomingMessage($this->agent, 'interactive', $input, substr($input, 1));
        }
        if (str_starts_with($input, 'media:')) {
            [, $id, $mime] = explode(':', $input, 3);

            return new IncomingMessage($this->agent, 'media', null, null, $id, $mime);
        }

        return new IncomingMessage($this->agent, 'text', $input);
    }

    protected function bodies(array $replies): string
    {
        return implode("\n", array_map(fn ($r) => $r->body, $replies));
    }
}
