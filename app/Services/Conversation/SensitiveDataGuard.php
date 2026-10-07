<?php

namespace App\Services\Conversation;

class SensitiveDataGuard
{
    public function containsIdentifyingData(string $text): bool
    {
        if (preg_match('/[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]/i', $text)) {
            return true;
        }

        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text)) {
            return true;
        }

        $digits = preg_replace('/[\s.\-\/()]/', '', $text);

        return (bool) preg_match('/\d{10,}/', $digits);
    }
}
