<?php

namespace App\Services\Documents;

/** Costo in dollari di una richiesta all'AI, dai token e dai prezzi per milione di token in configurazione. */
class AiCost
{
    public static function usd(int $inputTokens, int $outputTokens): ?float
    {
        $in = config('services.anthropic.price_input');
        $out = config('services.anthropic.price_output');
        if ($in === null || $out === null) {
            return null;
        }

        return round(($inputTokens * (float) $in + $outputTokens * (float) $out) / 1_000_000, 6);
    }
}
