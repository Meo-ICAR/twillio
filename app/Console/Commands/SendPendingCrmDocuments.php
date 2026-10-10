<?php

namespace App\Console\Commands;

use App\Jobs\SendDocumentsToCrm;
use App\Models\LoanRequest;
use Illuminate\Console\Command;

class SendPendingCrmDocuments extends Command
{
    protected $signature = 'crm:send-documents {--loan= : Codice di una sola pratica}';

    protected $description = 'Rimette in coda la consegna al CRM dei documenti non ancora inviati delle pratiche perfezionate (anche quelli arrivati dopo).';

    public function handle(): int
    {
        $loans = LoanRequest::query()
            ->whereNotNull('perfected_at')->where('is_test', false)
            ->when($this->option('loan'), fn ($q, $code) => $q->where('code', $code))
            ->whereHas('attachments', fn ($q) => $q->whereNull('crm_sent_at'))
            ->get();

        foreach ($loans as $loan) {
            SendDocumentsToCrm::dispatch($loan->id);
        }

        $this->info("{$loans->count()} pratiche rimesse in coda.");

        return self::SUCCESS;
    }
}
