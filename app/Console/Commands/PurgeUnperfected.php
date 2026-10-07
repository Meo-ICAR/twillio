<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Console\Command;

class PurgeUnperfected extends Command
{
    protected $signature = 'finanziamento:purge {--dry-run : Mostra cosa verrebbe cancellato senza cancellare}';

    protected $description = 'Cancella le pratiche non perfezionate oltre il termine di conservazione, con i loro file';

    public function handle(): int
    {
        $days = (int) config('privacy.retention_days');
        $expired = LoanRequest::where('status', '!=', 'perfezionata')
            ->where('created_at', '<', now()->subDays($days));

        $count = $expired->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} pratiche non perfezionate da oltre {$days} giorni (nessuna cancellazione: dry-run).");

            return self::SUCCESS;
        }

        $expired->get()->each(function (LoanRequest $loan) {
            // Chiude le conversazioni ancora aperte sulla pratica: senza di essa non potrebbero proseguire.
            Conversation::where('loan_request_id', $loan->id)->where('status', 'attiva')->get()
                ->each(fn (Conversation $conv) => $conv->update(['status' => 'annullata', 'data' => []]));
            $loan->delete();
        });

        $this->info("Cancellate {$count} pratiche non perfezionate da oltre {$days} giorni.");

        return self::SUCCESS;
    }
}
