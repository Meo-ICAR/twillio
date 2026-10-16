<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\Log;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Document;
use Unico\Core\Models\PdfModule;
use Unico\Core\Pdf\ModulePrinter;
use Unico\Core\Signature\PhoneNumber;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Signature\SignerInput;
use Unico\Core\Support\EmailAddress;

/** Compila i moduli dei task, li registra come Document del core agganciati alla pratica e li manda in firma. */
class LoanDocumentProducer
{
    public const SIGNER_SLOT = 'cliente';

    public function __construct(
        private LoanTaskResolver $tasks,
        private LoanModuleData $data,
        private ModulePrinter $printer,
        private SignatureRequestService $signatures,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     *
     * @throws DocumentProductionException per qualunque errore (dati mancanti, core non raggiungibile, PDF non compilabile)
     */
    public function produce(LoanRequest $loan, Company $company, string $stato): array
    {
        try {
            return $this->run($loan, $company, $stato);
        } catch (DocumentProductionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Solo il tipo di errore: il messaggio può contenere dati personali.
            Log::error('Produzione documenti non riuscita', ['loan' => $loan->code, 'exception' => $e::class]);
            $loan->update(['signature_status' => 'errore']);

            throw new DocumentProductionException('Non sono riuscito a preparare i documenti per la firma: riprova dal pannello.', 0, $e);
        }
    }

    /** @return array{sent: int, skipped: int} */
    private function run(LoanRequest $loan, Company $company, string $stato): array
    {
        $modules = $this->tasks->modules($company, $stato, $loan);
        if ($modules->isEmpty()) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $signer = $this->signer($loan);
        $skipped = 0;
        $documents = [];
        $lastModule = null;

        try {
            foreach ($modules as $module) {
                $lastModule = $module;
                $document = $this->document($loan, $company, $module);
                if ($this->alreadyInSignature($document)) {
                    $skipped++;

                    continue;
                }

                $this->attachPdf($document, $module, $loan);
                $documents[] = $document;
            }

            // Un solo plico a Yousign: il cliente riceve una richiesta e firma tutti i moduli con un solo codice OTP.
            if ($documents !== []) {
                $this->signatures->sendBundle($documents, [$signer]);
            }
        } catch (\Throwable $e) {
            // Solo il tipo di errore: il messaggio può contenere dati personali.
            Log::error('Produzione documenti non riuscita', ['loan' => $loan->code, 'module' => $lastModule?->id, 'exception' => $e::class]);
            $loan->update(['signature_status' => 'errore']);

            throw new DocumentProductionException('Non sono riuscito a preparare i documenti per la firma: riprova dal pannello.', 0, $e);
        }

        $sent = count($documents);

        if ($sent > 0) {
            $loan->update(['signature_status' => 'in_attesa', 'documents_sent_at' => now()]);
        } elseif ($skipped > 0 && ! $loan->signed_at && $loan->signature_status !== 'in_attesa') {
            // Firme già aperte (anche se un invio precedente non ha salvato lo stato): la pratica è in attesa.
            $loan->update(['signature_status' => 'in_attesa']);
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function signer(LoanRequest $loan): SignerInput
    {
        $s = $this->data->signer($loan);
        $missing = array_keys(array_filter(['nome' => $s['first_name'], 'cognome' => $s['last_name'], 'email' => $s['email'], 'cellulare' => $s['phone']], fn ($v) => blank($v)));
        if ($missing !== []) {
            $this->fail($loan, 'Mancano dati per la firma: '.implode(', ', $missing).'. Controlla dal pannello.');
        }

        // L'OTP parte per SMS: serve un cellulare (italiano o estero in forma internazionale) e un'email scritta bene.
        if (($error = EmailAddress::check($s['email'])) !== null) {
            $this->fail($loan, "Email del cliente non valida: {$error} Correggila dal pannello.");
        }
        if (($error = PhoneNumber::check($s['phone'], mobileOnly: true, allowForeign: true)) !== null) {
            $this->fail($loan, "Cellulare del cliente non valido: {$error} Correggilo dal pannello.");
        }

        return new SignerInput(self::SIGNER_SLOT, SignerRole::Client, $s['first_name'], $s['last_name'], EmailAddress::normalize($s['email']), PhoneNumber::toE164($s['phone']), $s['tax_code'], 'client', (string) $loan->id);
    }

    private function fail(LoanRequest $loan, string $message): never
    {
        $loan->update(['signature_status' => 'errore']);

        throw new DocumentProductionException($message);
    }

    private function document(LoanRequest $loan, Company $company, PdfModule $module): Document
    {
        return Document::firstOrCreate(
            ['documentable_type' => 'loan_request', 'documentable_id' => $loan->id, 'document_type_id' => $module->document_type_id],
            ['company_id' => $company->core_company_id, 'name' => $module->name.' '.$loan->code, 'channel' => 'generato'],
        );
    }

    /** Firma già aperta o completata: non si rifà né si duplica la richiesta. */
    private function alreadyInSignature(Document $document): bool
    {
        return $document->is_signed || $document->signatureRequests()->open()->exists();
    }

    private function attachPdf(Document $document, PdfModule $module, LoanRequest $loan): void
    {
        $pdf = $this->printer->render($module, $this->data->for($loan));

        $document->clearMediaCollection(Document::COLLECTION);
        $document->addMediaFromString($pdf)->usingFileName(str($module->name)->slug().'-'.$loan->code.'.pdf')->toMediaCollection(Document::COLLECTION);
    }
}
