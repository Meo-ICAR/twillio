<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
     * Handshake POST: Gestione messaggi e scelte dell'agente
     */
    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        Log::info('WhatsApp webhook ricevuto', $data);
        $entry = $data['entry'][0]['changes'][0]['value'] ?? null;

        if (isset($entry['messages'][0])) {
            $message = $entry['messages'][0];
            $from = $message['from'];
            $type = $message['type'] ?? 'text';

            $userSelection = null;

            // 1. Se l'agente ha CLICCATO su uno dei 3 pulsanti
            if ($type === 'interactive') {
                $userSelection = $message['interactive']['button_reply']['id'] ?? null;
            } 
            // 2. Se l'agente ha DIGITATO testo o numeri (es. 1, 2, 3)
            elseif ($type === 'text') {
                $text = strtolower(trim($message['text']['body'] ?? ''));

                if (in_array($text, ['1', 'preventivo', 'richiedi preventivo'])) {
                    $userSelection = 'btn_preventivo';
                } elseif (in_array($text, ['2', 'finanziamento', 'pratica', 'invia finanziamento'])) {
                    $userSelection = 'btn_finanziamento';
                } elseif (in_array($text, ['3', 'stato', 'avanzamento', 'stato pratiche'])) {
                    $userSelection = 'btn_stato';
                }
            }

            // Gestione delle risposte in base all'opzione scelta
            switch ($userSelection) {
                case 'btn_preventivo':
                    $this->sendTextMessage(
                        $from, 
                        "📄 *Richiesta Preventivo*\n\nPerfetto! Per procedere, indicami l'importo desiderato e la tipologia di bene o servizio."
                    );
                    break;

                case 'btn_finanziamento':
                    $this->sendTextMessage(
                        $from, 
                        "📋 *Incontro/Invio Pratica Finanziamento*\n\nPer favore, allega i documenti del cliente (PDF o immagini) direttamente in questa chat."
                    );
                    break;

                case 'btn_stato':
                    $this->sendTextMessage(
                        $from, 
                        "🔍 *Avanzamento Pratiche*\n\nInserisci il codice fiscale del cliente o il numero identificativo della pratica per verificare lo stato."
                    );
                    break;

                default:
                    // Se l'utente scrive per la prima volta o scrive un testo non riconosciuto
                    $this->sendMenuButtons($from);
                    break;
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Invia il menu con i 3 pulsanti interattivi
     */
    private function sendMenuButtons(string $to)
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');

        $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'header' => [
                    'type' => 'text',
                    'text' => 'Portale Agenti'
                ],
                'body' => [
                    'text' => "Ciao! Benvenuto nel servizio agenti. Come posso aiutarti oggi? Seleziona un'opzione dal menu:"
                ],
                'footer' => [
                    'text' => 'Premi un bottone o rispondi 1, 2 o 3'
                ],
                'action' => [
                    'buttons' => [
                        [
                            'type' => 'reply',
                            'reply' => [
                                'id' => 'btn_preventivo',
                                'title' => 'Richiedi Preventivo'
                            ]
                        ],
                        [
                            'type' => 'reply',
                            'reply' => [
                                'id' => 'btn_finanziamento',
                                'title' => 'Invia Finanziamento'
                            ]
                        ],
                        [
                            'type' => 'reply',
                            'reply' => [
                                'id' => 'btn_stato',
                                'title' => 'Stato Pratiche'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = Http::withToken($token)->post($url, $payload);

        if ($response->failed()) {
            Log::error('Errore invio menu interattivo:', (array) $response->json());
        }
    }

    /**
     * Helper per l'invio di messaggi di testo semplice
     */
    private function sendTextMessage(string $to, string $text)
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');

        $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

        $response = Http::withToken($token)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);

        if ($response->failed()) {
            Log::error('Errore invio messaggio di testo:', (array) $response->json());
        }
    }
}