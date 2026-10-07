<?php

namespace App\Services\Conversation;

final class Reply
{
    /** @param array<string,string> $options id => titolo */
    private function __construct(
        public readonly string $kind,
        public readonly string $body,
        public readonly array $options = [],
        public readonly string $label = 'Scegli',
    ) {}

    public static function text(string $body): self
    {
        return new self('text', $body);
    }

    /** @param array<string,string> $options id => titolo */
    public static function choice(string $body, array $options): self
    {
        $fitsButtons = count($options) <= 3
            && collect($options)->every(fn ($title) => mb_strlen($title) <= 20);

        return new self($fitsButtons ? 'buttons' : 'list', $body, $options);
    }
}
