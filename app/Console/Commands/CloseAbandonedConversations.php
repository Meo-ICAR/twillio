<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class CloseAbandonedConversations extends Command
{
    protected $signature = 'conversazioni:chiudi-abbandonate';

    protected $description = 'Chiude le conversazioni lasciate alla prima domanda da più di un\'ora';

    public function handle(): int
    {
        $closed = 0;
        Conversation::abandoned()->get()->each(function (Conversation $conv) use (&$closed) {
            // Il filtro SQL sulla cronologia è approssimato (colonna JSON): si ricontrolla sul modello.
            if (empty($conv->history)) {
                $conv->update(['status' => 'annullata', 'data' => []]);
                $closed++;
            }
        });

        $this->info("Chiuse {$closed} conversazioni abbandonate.");

        return self::SUCCESS;
    }
}
