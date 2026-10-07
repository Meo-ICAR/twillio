<?php

namespace App\Http\Controllers;

use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Handshake GET per verifica iniziale del Webhook
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Token non valido', 403);
    }

    /**
     * Messaggi in arrivo: il motore decide cosa rispondere, qui si inviano i Reply.
     * Se un invio fallisce la transazione si annulla e lo stato non avanza.
     */
    public function handleWebhook(Request $request, ConversationEngine $engine, WhatsAppClient $client)
    {
        $message = IncomingMessage::fromWebhook($request->all());

        if ($message) {
            try {
                DB::transaction(function () use ($engine, $client, $message) {
                    foreach ($engine->handle($message) as $reply) {
                        if (! $client->send($message->from, $reply)) {
                            throw new \RuntimeException('Invio WhatsApp fallito');
                        }
                    }
                });
            } catch (\Throwable $e) {
                Log::error('Errore gestione webhook WhatsApp: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}
