<?php

use App\Http\Controllers\WhatsAppController;
use App\Models\Company;
use App\Services\Conversation\FlowGraph;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/api/whatsapp/webhook', [WhatsAppController::class, 'verifyWebhook']);
Route::post('/api/whatsapp/webhook', [WhatsAppController::class, 'handleWebhook']);

Route::get('/', function () {
    return view('welcome');
});

Route::get('/grafo-domande', fn () => response((new FlowGraph)->html()));

Route::get('/compliance', fn () => view('compliance', ['company' => Company::current()]));

Route::get('/privacy', fn () => view('privacy', ['company' => Company::current()]));

Route::get('/cancellazione-dati', fn () => view('data-deletion', ['company' => Company::current()]));

Route::get('/test-whatsapp', function () {
    $phoneNumberId = config('services.whatsapp.phone_number_id');
    $token = config('services.whatsapp.token');

    // Inserisci il tuo numero di telefono reale con prefisso (senza il +)
    $recipient = '393927968199';

    $response = Http::withToken($token)->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipient,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => '🚀 Ciao! Questo è un messaggio di prova inviato dal tuo server Laravel 13!',
        ],
    ]);

    return response()->json([
        'status' => $response->status(),
        'body' => $response->json(),
    ]);
});
