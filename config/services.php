<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // Dollari per milione di token del modello in uso (Claude Opus 5.5: 4 in ingresso, 20 in uscita): servono a stimare il costo di ogni lettura.
        'price_input' => env('ANTHROPIC_PRICE_INPUT', 4.0),
        'price_output' => env('ANTHROPIC_PRICE_OUTPUT', 20.0),
        // Credito residuo stimato: l'API non lo espone. Si indica il saldo (in dollari) letto in console a una data (AAAA-MM-GG) e si toglie
        // la spesa del cost report, che richiede una chiave amministrativa (sk-ant-admin...). Da riallineare a ogni ricarica.
        'admin_key' => env('ANTHROPIC_ADMIN_API_KEY'),
        'credit_balance' => env('ANTHROPIC_CREDIT_BALANCE'),
        'credit_as_of' => env('ANTHROPIC_CREDIT_AS_OF'),
    ],

    'whatsapp' => [
        'verify_token' => env('META_WA_VERIFY_TOKEN'),
        'token' => env('META_WA_TOKEN'),
        'phone_number_id' => env('META_WA_PHONE_NUMBER_ID'),
        // Servono solo al comando whatsapp:extend-token (scambio del token breve con uno da circa 60 giorni).
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
    ],

    // UnicoBPM: gli eventi (es. pratica.perfezionata) avviano i processi di presa in carico. Senza indirizzo o chiave non si invia nulla.
    'bpm' => [
        'url' => env('BPM_API_URL'),
        'key' => env('BPM_BRIDGE_API_KEY'),
        // Comunica a UnicoBPM l'esito delle chiamate API (solo metadati) per il suo trace «Log API».
        'trace' => env('BPM_TRACE_API', true),
    ],

];
