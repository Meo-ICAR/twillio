<?php

namespace App\Services\Whatsapp;

use App\Services\Conversation\Reply;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppClient
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    public function send(string $to, Reply $reply): bool
    {
        $response = Http::withToken(config('services.whatsapp.token'))->timeout(10)->connectTimeout(5)
            ->post(self::BASE.'/'.config('services.whatsapp.phone_number_id').'/messages', $this->payload($to, $reply));

        if ($response->failed()) {
            Log::error('Errore invio WhatsApp', (array) $response->json());

            return false;
        }

        return true;
    }

    /** @return array{body: string, mime: string}|null */
    public function downloadMedia(string $mediaId): ?array
    {
        $token = config('services.whatsapp.token');

        $meta = Http::withToken($token)->timeout(10)->connectTimeout(5)->get(self::BASE.'/'.$mediaId);
        if ($meta->failed() || ! $meta->json('url')) {
            Log::error('Errore recupero media WhatsApp', ['media' => $mediaId]);

            return null;
        }

        $file = Http::withToken($token)->timeout(10)->connectTimeout(5)->get($meta->json('url'));
        if ($file->failed()) {
            Log::error('Errore download media WhatsApp', ['media' => $mediaId]);

            return null;
        }

        return ['body' => $file->body(), 'mime' => (string) $meta->json('mime_type')];
    }

    private function payload(string $to, Reply $reply): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];

        return $base + match ($reply->kind) {
            'text' => ['type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($reply->body, 0, 4096)]],
            'buttons' => ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($reply->body, 0, 1024)],
                'action' => ['buttons' => collect($reply->options)->map(fn ($title, $id) => [
                    'type' => 'reply', 'reply' => ['id' => $id, 'title' => mb_substr($title, 0, 20)],
                ])->values()->all()],
            ]],
            'list' => ['type' => 'interactive', 'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr($reply->body, 0, 1024)],
                'action' => [
                    'button' => mb_substr($reply->label, 0, 20),
                    'sections' => [['title' => 'Opzioni', 'rows' => collect($reply->options)->map(fn ($title, $id) => [
                        'id' => (string) $id, 'title' => mb_substr($title, 0, 24),
                    ])->values()->all()]],
                ],
            ]],
        };
    }
}
