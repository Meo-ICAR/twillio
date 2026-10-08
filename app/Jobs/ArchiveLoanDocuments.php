<?php

namespace App\Jobs;

use App\Models\LoanRequest;
use App\Services\Documents\SharePointUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Archivia su SharePoint gli allegati di una pratica perfezionata, in una cartella col codice pratica.
 * Salta le pratiche di prova, gli allegati rifiutati e i file non più sul disco. Un errore lascia la pratica
 * senza `documents_archived_at` e fa ripartire il job (3 tentativi): serve un worker di coda in esecuzione.
 */
class ArchiveLoanDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300];

    public function __construct(public int $loanId) {}

    public function handle(SharePointUploader $uploader): void
    {
        $loan = LoanRequest::find($this->loanId);
        if (! $loan || $loan->is_test) {
            return;
        }

        $disk = Storage::disk('local');
        foreach ($loan->attachments()->with('praticaDocument')->orderBy('id')->get() as $attachment) {
            if ($attachment->praticaDocument?->status === 'rejected' || ! $disk->exists($attachment->path)) {
                continue;
            }

            $uploader->upload($loan->code, "{$attachment->kind}-{$attachment->id}.".pathinfo($attachment->path, PATHINFO_EXTENSION), $disk->get($attachment->path));
        }

        $loan->update(['documents_archived_at' => now()]);
    }
}
