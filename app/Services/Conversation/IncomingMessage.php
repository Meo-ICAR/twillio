<?php

namespace App\Services\Conversation;

final class IncomingMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $replyId = null,
        public readonly ?string $mediaId = null,
        public readonly ?string $mime = null,
    ) {}

    public static function fromWebhook(array $payload): ?self
    {
        $m = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        if (! $m || empty($m['from'])) {
            return null;
        }

        $type = $m['type'] ?? 'text';

        return match ($type) {
            'text' => new self($m['from'], 'text', trim($m['text']['body'] ?? '')),
            'interactive' => self::fromInteractive($m),
            'image', 'document' => new self(
                $m['from'], 'media', $m[$type]['caption'] ?? null, null, $m[$type]['id'] ?? null, $m[$type]['mime_type'] ?? null
            ),
            default => new self($m['from'], 'unsupported'),
        };
    }

    private static function fromInteractive(array $m): self
    {
        $reply = $m['interactive'][$m['interactive']['type'] ?? ''] ?? [];

        return new self($m['from'], 'interactive', $reply['title'] ?? null, $reply['id'] ?? null);
    }
}
