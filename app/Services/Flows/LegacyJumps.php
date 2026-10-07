<?php

namespace App\Services\Flows;

/** Converte i salti scritti come `next` (nodo fisso o mappa) nelle righe della tabella dei salti. */
class LegacyJumps
{
    /**
     * @param  array<string|int,string>|null  $nextMap  condizione => nodo
     * @return list<array{when: string, go_to: string}> un salto fisso diventa il salto predefinito «*»
     */
    public static function fromColumns(?string $nextTo, ?array $nextMap): array
    {
        if ($nextMap) {
            $jumps = [];
            foreach ($nextMap as $when => $goTo) {
                $jumps[] = ['when' => (string) $when, 'go_to' => $goTo];
            }

            return $jumps;
        }

        return filled($nextTo) ? [['when' => '*', 'go_to' => $nextTo]] : [];
    }
}
